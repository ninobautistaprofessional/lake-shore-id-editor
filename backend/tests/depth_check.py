import re

with open(r'c:\xampp\htdocs\lake-shore-id-editor\backend\api.php', 'r', encoding='utf-8-sig') as f:
    lines = f.readlines()

depth = 0
report = []
for i, line in enumerate(lines, 1):
    # strip strings and comments so braces inside them don't skew depth
    s = re.sub(r"'(?:[^'\\]|\\.)*'", "''", line)
    s = re.sub(r'"(?:[^"\\]|\\.)*"', '""', s)
    s = re.sub(r'//.*$', '', s)
    s = re.sub(r'/\*.*$', '', s)
    opens = s.count('{')
    closes = s.count('}')
    if re.match(r'\s*if \(\$action===', line):
        report.append((i, depth))
    depth += opens - closes

print('Final depth:', depth)
for i, d in report:
    marker = ' <<<' if d != 0 else ''
    if 300 <= i <= 1100 and (d != 0 or 300 <= i <= 500):
        print(f'action-if line {i}: depth={d}{marker}')
