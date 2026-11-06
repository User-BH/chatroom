-- کاربر برنامه با همان دسترسی محدود README
CREATE USER 'chatroom'@'%' IDENTIFIED BY 'local-dev-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON chatroom_secure.* TO 'chatroom'@'%';
