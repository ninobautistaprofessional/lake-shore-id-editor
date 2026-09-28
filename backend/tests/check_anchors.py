# -*- coding: utf-8 -*-
# Quick scan of api.php action routing for card/templates SELECTs.
import io

API = r'c:\xampp\htdocs\lake-shore-id-editor\backend\api.php'
c = io.open(API, encoding='utf-8', newline='').read()
lines = c.split('\r\n')
keys = ["'card'", '"card"', "'templates'", 'SELECT * FROM id_cards',
        'SELECT * FROM id_templates', 'verifyCsrf', 'function input']
for i, ln in enumerate(lines, 1):
    for k in keys:
        if k in ln:
            print('%5d | %s' % (i, ln.strip()[:150]))
            break
