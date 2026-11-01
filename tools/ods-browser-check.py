#!/usr/bin/env python3
"""Exercise the ODS template → preview → commit → export → update flow on the dev instance."""

import importlib.util
import io
import sys
import uuid
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

from playwright.sync_api import expect, sync_playwright

spec = importlib.util.spec_from_file_location("browser_check", Path(__file__).with_name("browser-check.py"))
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

NS = {
    "office": "urn:oasis:names:tc:opendocument:xmlns:office:1.0",
    "table": "urn:oasis:names:tc:opendocument:xmlns:table:1.0",
    "text": "urn:oasis:names:tc:opendocument:xmlns:text:1.0",
}
for prefix, uri in NS.items():
    ET.register_namespace(prefix, uri)


def cell_text(element):
    text = element.text or ""
    for child in element:
        if child.tag == f"{{{NS['text']}}}s":
            text += " " * int(child.attrib.get(f"{{{NS['text']}}}c", "1"))
        elif child.tag == f"{{{NS['text']}}}tab":
            text += "\t"
        elif child.tag == f"{{{NS['text']}}}line-break":
            text += "\n"
        else:
            text += cell_text(child)
        text += child.tail or ""
    return text


def table_rows(blob):
    with zipfile.ZipFile(io.BytesIO(blob)) as archive:
        root = ET.fromstring(archive.read("content.xml"))
    table = root.find('.//table:table[@table:name="Activos"]', NS)
    return [["\n".join(cell_text(p) for p in cell.findall("text:p", NS))
             for cell in row.findall("table:table-cell", NS)]
            for row in table.findall("table:table-row", NS)]


def with_rows(blob, rows):
    """Fill the downloaded document, preserving its metadata and reference sheets."""
    with zipfile.ZipFile(io.BytesIO(blob)) as archive:
        files = {name: archive.read(name) for name in archive.namelist()}
    root = ET.fromstring(files["content.xml"])
    table = root.find('.//table:table[@table:name="Activos"]', NS)
    for row in list(table):
        table.remove(row)
    for values in rows:
        row = ET.SubElement(table, f"{{{NS['table']}}}table-row")
        for value in values:
            cell = ET.SubElement(row, f"{{{NS['table']}}}table-cell", {f"{{{NS['office']}}}value-type": "string"})
            ET.SubElement(cell, f"{{{NS['text']}}}p").text = value
    files["content.xml"] = ET.tostring(root, encoding="utf-8", xml_declaration=True)
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, data in files.items():
            archive.writestr(name, data)
    return output.getvalue()


def close_dialog(page):
    page.get_by_role("dialog").get_by_role("button", name="Close", exact=True).click()
    page.get_by_role("dialog").wait_for(state="hidden")


def preview(page, dataset, blob):
    page.get_by_role("button", name="Importar / deshacer").click()
    page.get_by_label("Conjunto de datos").fill(dataset)
    page.locator('input[type="file"]').set_input_files({
        "name": "activos.ods", "mimeType": "application/vnd.oasis.opendocument.spreadsheet", "buffer": blob,
    })
    page.get_by_role("button", name="Revisar planilla", exact=True).click()
    page.get_by_role("heading", name="Revisar antes de importar").wait_for()


def main():
    tag = uuid.uuid4().hex[:8]
    name = f"Activo ODS {tag}"
    updated_name = f"Activo ODS actualizado {tag}"
    dataset = f"browser-ods-{tag}"
    gate.SHOTS.mkdir(exist_ok=True)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(args=["--host-resolver-rules=MAP openstreetmap.org ~NOTFOUND, MAP *.openstreetmap.org ~NOTFOUND"])
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        gate.mock_osm(context)
        page = context.new_page()
        errors = gate.console_errors(page)
        gate.log_in(page)
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30000)
        page.get_by_role("button", name="Importar / deshacer").click()
        with page.expect_download() as download:
            page.get_by_role("button", name="Descargar plantilla ODS").click()
        template = Path(download.value.path()).read_bytes()
        assert download.value.suggested_filename.endswith(".ods")
        with zipfile.ZipFile(io.BytesIO(template)) as archive:
            assert b"content-validation" in archive.read("content.xml"), "Template lacks taxonomy dropdowns"
        headers = table_rows(template)[0]
        close_dialog(page)
        values = ["", "", name, "salud", "otro", "", "", "Calle de prueba 123", "+56911111111", "", "", "", "Lunes", "Conservar nota", "Registro local", ""]
        invalid_values = values.copy()
        invalid_values[3] = "Clasificación desconocida"
        document = with_rows(template, [headers, invalid_values])
        preview(page, dataset, document)
        assert gate.registry_feature(page, name) is None, "Preview wrote an asset"
        page.get_by_label("Corregir clasificación de fila 2").select_option(label="Salud · Otro")
        page.get_by_role("button", name="Aplicar clasificación", exact=True).click()
        expect(page.get_by_text("Nuevo", exact=True)).to_be_visible()
        assert gate.registry_feature(page, name) is None, "Classification correction wrote an asset"
        page.screenshot(path=str(gate.SHOTS / "ods-preview.png"), full_page=False)
        page.get_by_role("button", name="Confirmar importación", exact=True).click()
        expect(page.get_by_text("1 creados, 0 actualizados", exact=False)).to_be_visible()
        first = gate.registry_feature(page, name)
        assert first and first["geometry"] is None and first["phone"] == "+56911111111"
        close_dialog(page)
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector('[data-testid="map-canvas"]')
        gate._pane(page, "Análisis")
        page.get_by_label("Buscar activos", exact=True).fill(name)
        page.get_by_role("button", name="Exportar resultados", exact=True).click()
        page.get_by_text("ODS — planilla editable", exact=False).click()
        with page.expect_download() as download:
            page.get_by_role("button", name="Exportar", exact=True).last.click()
        exported = Path(download.value.path()).read_bytes()
        page.get_by_role("dialog").wait_for(state="hidden")
        rows = table_rows(exported)
        assert len(rows) == 2 and rows[1][0].endswith(f":{first['id']}"), "Export lost the stable Registry ID"
        assert rows[1][8] == "", "Unconfirmed export included a telephone number"
        rows[1][2] = updated_name
        rows[1][13] = ""
        preview(page, dataset, with_rows(exported, rows))
        expect(page.get_by_text("Actualización", exact=True)).to_be_visible()
        page.get_by_role("button", name="Confirmar importación", exact=True).click()
        expect(page.get_by_text("0 creados, 1 actualizados", exact=False)).to_be_visible()
        updated = gate.registry_feature(page, updated_name)
        assert updated and updated["id"] == first["id"], "Edited ODS created a second asset"
        assert updated["phone"] == "+56911111111" and updated["notes"] == "Conservar nota", "Blank cells erased optional details"
        close_dialog(page)
        # Re-uploading a new row that matches an existing address must require review.
        values[2] = updated_name
        preview(page, dataset, with_rows(template, [headers, values]))
        expect(page.get_by_text("Posibles duplicados — compruebe antes de importar")).to_be_visible()
        expect(page.get_by_role("button", name="Confirmar importación", exact=True)).to_be_disabled()
        page.screenshot(path=str(gate.SHOTS / "ods-duplicate-review.png"), full_page=False)
        close_dialog(page)
        # Test data is retired through the public door, preserving history like a user edit.
        response = page.evaluate("""async id => {
            const response = await fetch(`/index.php/apps/territorio/features/${id}`, {
                method: 'DELETE', headers: { requesttoken: window.OC.requestToken },
            }); return response.status;
        }""", first["id"])
        assert response == 200
        assert not errors, f"Browser errors: {errors[:3]}"
        browser.close()
    print("OK ODS template, no-write preview, selected import, stable export/update, blank preservation and duplicate review")
    return 0


if __name__ == "__main__":
    sys.exit(main())
