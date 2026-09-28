LAKE SHORE ID EDITOR - FINAL UPDATE

1. Extract to C:\xampp\htdocs\lake-shore-id-editor
2. Start Apache and MySQL.
3. Import database.sql in phpMyAdmin.
4. Put lsc-logo.png in frontend/public/.
5. Open CMD: cd C:\xampp\htdocs\lake-shore-id-editor\frontend
6. Run: npm install
7. Run: npm run dev
8. Open the URL shown (usually http://localhost:5173).
9. Create the first administrator account.

Security: sessions are HttpOnly, API routes require login, modifying requests use CSRF, and upload folders block PHP execution.
