#!/usr/bin/env python3
"""Persist a pin, optional information and overlapping custom areas through the browser."""
import importlib.util
from pathlib import Path
import uuid

from playwright.sync_api import sync_playwright, expect

spec = importlib.util.spec_from_file_location('browser_check', Path(__file__).with_name('browser-check.py'))
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


def main():
    token = 'Inventory-' + uuid.uuid4().hex[:8]
    failures = []
    check.SHOTS.mkdir(exist_ok=True)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        context = browser.new_context(viewport={'width': 1400, 'height': 900})
        check.mock_osm(context)
        page = context.new_page()
        errors = check.console_errors(page)
        check.log_in(page)
        page.wait_for_selector('[data-testid="map-canvas"]')
        check.to_list(page)
        page.get_by_role('button', name='Agregar', exact=True).click()
        page.get_by_label('Nombre', exact=True).fill(token + ' pin')
        expect(page.get_by_label('Latitud', exact=True)).not_to_be_visible()
        expect(page.get_by_label('Teléfono', exact=True)).not_to_be_visible()
        page.locator('[data-testid="map-canvas"]').click(position={'x': 650, 'y': 350})
        expect(page.get_by_text('Pin colocado en el mapa.', exact=True)).to_be_visible()
        page.get_by_label('Dirección (opcional)', exact=True).fill('Dirección escrita por el equipo')
        page.locator('summary', has_text='Contacto (opcional)').click()
        page.get_by_label('Teléfono', exact=True).fill('+56 9 1234 5678')
        page.locator('summary', has_text='Más información (opcional)').click()
        page.get_by_label('Notas', exact=True).fill('Información que debe conservarse')
        check.save(page, failures, 'pin asset')
        saved = check.registry_feature(page, token + ' pin')
        assert saved and saved['geometry']['type'] == 'Point', 'map pin was not persisted'
        assert saved['phone'] == '+56 9 1234 5678'
        coordinates = saved['geometry']['coordinates']
        check.to_list(page)
        page.locator(check.SEARCH).fill(token + ' pin')
        page.wait_for_timeout(500)
        check.open_first_row(page)
        page.get_by_label('Nombre', exact=True).fill(token + ' edited')
        expect(page.get_by_label('Teléfono', exact=True)).not_to_be_visible()
        check.save(page, failures, 'asset with closed optional sections')
        saved = check.registry_feature(page, token + ' edited')
        assert saved['geometry']['coordinates'] == coordinates
        assert saved['phone'] == '+56 9 1234 5678'
        assert saved['notes'] == 'Información que debe conservarse'
        check.to_list(page)
        page.locator(check.SEARCH).fill('')
        page.get_by_role('button', name='Agregar', exact=True).click()
        page.get_by_label('Nombre', exact=True).fill(token + ' unlocated')
        check.save(page, failures, 'asset awaiting location')
        assert check.registry_feature(page, token + ' unlocated')['geometry'] is None
        for index in range(2):
            check.to_list(page)
            page.locator(check.SEARCH).fill('')
            page.get_by_label('Más opciones de dibujo', exact=True).click()
            page.get_by_role('button', name='Dibujar área personalizada', exact=True).click()
            canvas = page.locator('[data-testid="map-canvas"]')
            x, y = 560 + index * 20, 240 + index * 20
            for point in [(x,y),(x+110,y),(x+110,y+90),(x,y+90),(x,y)]:
                canvas.click(position={'x': point[0], 'y': point[1]})
            expect(page.get_by_label('Nombre', exact=True)).to_be_visible()
            assert page.locator('.territorio-history').count() == 0, 'drawing selected an existing record'
            page.get_by_label('Nombre', exact=True).fill(token + f' area {index}')
            check.save(page, failures, 'custom area')
            area = check.registry_feature(page, token + f' area {index}')
            assert area and area['geometry']['type'] == 'Polygon'
        page.reload(wait_until='domcontentloaded')
        page.wait_for_selector('[data-testid="map-canvas"]')
        stored = [record for record in check.registry_features(page) if record.get('name', '').startswith(token)]
        assert len(stored) == 4
        check.to_list(page)
        page.locator(check.SEARCH).fill(token)
        page.wait_for_timeout(500)
        page.screenshot(path=str(check.SHOTS / 'inventory-pin-areas.png'))
        for record in stored:
            status = page.evaluate('''async id => (await fetch(`/index.php/apps/territorio/features/${id}`, {
                method: 'DELETE', headers: {requesttoken: window.OC.requestToken}
            })).status''', record['id'])
            assert status == 200
        assert not failures, failures
        assert not errors, errors
        browser.close()
    print('OK pin creation, optional-field preservation, unlocated asset and overlapping custom areas persisted through reload')


if __name__ == '__main__':
    main()
