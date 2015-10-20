# phpchat
A lightweight PHP chat server and client

## Summary
1. A simple chat server and client using PHP stream socket APIs
2. All connections are encrypted via SSL
3. Supported commands including create chat room, join chat room, private message, etc

## How to use
### Server:
1. Make sure php is installed, and can be found as /usr/bin/php
2. Change chat_server permission: > chmod 755 chat_server
3. Start server: > chat_server ip port

### Client:
1. Make sure php is installed, and can be found in PATH
2. Change chat_client permission: > chmod 755 chat_client
3. Start client: > chat_client ip port
4. Type '/help' at anytime for commands
