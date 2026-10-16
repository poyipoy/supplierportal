"""Independent ZIP/XML and CSV parsing of the four HTTP/queue-generated files."""
import csv
import hashlib
import io
import json
from pathlib import Path
from zipfile import ZipFile
import xml.etree.ElementTree as ET

base = Path(__file__).resolve().parent.parent
namespace = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
values = ["'=Pilot é, \"note\"", 'PO/08/2026/911', 'USD']
cases = []
for locale, headings in [('en', ['Remark', 'PO Number', 'Currency']), ('id', ['Catatan', 'Nomor PO', 'Mata Uang'])]:
    for extension in ['xlsx', 'csv']:
        file = base / f'http-output-{locale}.{extension}'
        data = file.read_bytes()
        if extension == 'csv':
            assert data.startswith(b'\xef\xbb\xbf'), 'Missing CSV UTF-8 BOM'
            rows = list(csv.reader(io.StringIO(data.decode('utf-8-sig'))))
        else:
            with ZipFile(file) as archive:
                strings = []
                if 'xl/sharedStrings.xml' in archive.namelist():
                    strings = [''.join(element.itertext()) for element in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('s:si', namespace)]
                sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
                rows = []
                for row in sheet.findall('s:sheetData/s:row', namespace):
                    cells = []
                    for cell in row.findall('s:c', namespace):
                        assert cell.find('s:f', namespace) is None, 'Unexpected formula'
                        value = cell.find('s:v', namespace)
                        cells.append(strings[int(value.text)] if cell.get('t') == 's' else ''.join(cell.find('s:is', namespace).itertext()) if cell.get('t') == 'inlineStr' else value.text if value is not None else '')
                    rows.append(cells)
        assert rows == [headings, values], (file.name, rows)
        cases.append({'file': file.name, 'sha256': hashlib.sha256(data).hexdigest(), 'status': 'PASS', 'rows': rows})
output = Path(__file__).with_name('http-file-comparison.json')
output.write_text(json.dumps({'status': 'PASS', 'scope': 'Independent stdlib parsing; four actual POST-dispatch-worker-finalizer files; exact headings/values/order/BOM and no decorative rows or formulas.', 'cases': cases}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'status': 'PASS', 'files': len(cases), 'artifact': str(output)}))
