import re
with open('c:\\xampp\\htdocs\\lake-shore-id-editor\\frontend\\src\\main.jsx', 'r') as f:
    content = f.read()
content = content.replace('async function load() {', 'async function initDashboard() {')
with open('c:\\xampp\\htdocs\\lake-shore-id-editor\\frontend\\src\\main.jsx', 'w') as f:
    f.write(content)
print('Done')