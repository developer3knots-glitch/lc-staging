@echo off
REM Cursor/VS Code git wrapper — use .git-real (locked .git folder workaround)
"C:\Program Files\Git\cmd\git.exe" --git-dir="C:\xampp\htdocs\WSlivechat\.git-real" --work-tree="C:\xampp\htdocs\WSlivechat" %*
