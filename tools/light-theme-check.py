#!/usr/bin/env python3
"""Territorio stays light without changing the host's chosen appearance."""
import importlib.util
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

spec = importlib.util.spec_from_file_location('browser_check', Path(__file__).with_name('browser-check.py'))
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        context = browser.new_context(viewport={'width': 1400, 'height': 900}, color_scheme='dark')
        check.mock_osm(context)
        page = context.new_page()
        errors = check.console_errors(page)
        check.log_in(page)
        page.wait_for_selector('[data-testid="map-canvas"]')
        host_background = page.evaluate("getComputedStyle(document.body).getPropertyValue('--color-main-background').trim()")
        def assert_light(locator):
            expect(locator).to_be_visible()
            values = locator.evaluate("el => { const s = getComputedStyle(el); return {background: s.getPropertyValue('--color-main-background').trim(), ink: s.color, scheme: s.colorScheme} }")
            assert values['background'] == '#ffffff', values
            assert values['ink'] == 'rgb(34, 34, 34)', values
            assert values['scheme'] == 'light', values
        assert_light(page.locator('.territorio-docked-panel'))
        check._pane(page, 'Análisis')
        assert_light(page.locator('.territorio-analisis'))
        page.screenshot(path=str(check.SHOTS / 'analytics-light-theme.png'))
        check._pane(page, 'Mapa')
        page.get_by_role('button', name='Importar / deshacer', exact=True).click()
        assert_light(page.get_by_role('dialog', name='Importar y deshacer'))
        page.screenshot(path=str(check.SHOTS / 'import-light-theme.png'))
        page.get_by_role('button', name='Close', exact=True).click()
        expect(page.get_by_role('dialog', name='Importar y deshacer')).not_to_be_visible()
        page.locator('.territorio-docked-panel').get_by_role('button', name='Exportar', exact=True).click()
        assert_light(page.get_by_role('dialog', name='Exportar', exact=True))
        assert page.evaluate("getComputedStyle(document.body).getPropertyValue('--color-main-background').trim()") == host_background
        assert not errors, errors
        browser.close()
    print('OK Territorio, Analytics and import/export dialogs remain light without changing the host theme')

if __name__ == '__main__':
    main()
