QUIZ WEBSITE - SETUP
1. Open config.php and change the admin password.
2. Upload this whole folder to your hosting (cPanel > public_html, or a subfolder).
   Requirements: PHP 7.4+ with pdo_sqlite (standard on most hosts). No MySQL needed.
3. Make sure the "data" folder is writable (permission 755 or 775).
4. Students open:  https://yourdomain.com/            (index.html)
   Admin opens:    https://yourdomain.com/admin.html  (password login)
Use HTTPS. Do not share the admin link with students.
The database is created automatically in data/quiz.sqlite - back it up if needed.
