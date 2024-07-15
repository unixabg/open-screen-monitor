# Open Screen Monitor (osm)
## An open-source screen monitoring tool with url filtering and policy enforcement features for Chrome.
- Initial concepts and work created by Andrew Coursen AndrewCoursen@gmail.com.
- Code contributed to the open source community by Andrew Coursen under the GNU General Public License v3.0.
- Project created at GitHub by Richard Nelson unixabg@gmail.com.

### chrome-extension
- The extension to be added to the target Chrome browser.

### php
- The server side to listen and manage the connections, presentation, and operations for open-screen-monitor.

### Authentication
- OAuth 2.0 with Google https://console.developers.google.com/

### Getting started
- Clone the repository
- Configure the chrome-extension for your deployment (FIXME)
- Install the server side PHP
- Make the data directory **Attention - DO NOT make this folder a public access folder from the web!**
 - The defaults will attempt to add osm to your domain name. So something like osm.yourdomain.com .
   - https://osm.yourdomain.com/ is the default location for the osm php files
   - ../osm-data is the default data location relative to the default osm php files
- Configure OAuth access to your Organizational Unit of your domain
 - Go to  https://console.developers.google.com/
 - Create a new API Project with name OpenScreenMonitor
 - Once created open the OSMOAuth project and enable the Admin SDK & Classroom API
 - Create the APIs Credentials of type OAuth client ID
  - Configure OAuth consent screen: yourDomain - OpenScreenMonitor
  - Application type: Web application
  - Set Authorized JavaScript origins and Authorized redirect URIs
  - After you choose Create you should get an OAuth Client screen with the following information:
   - Here is your client ID
   - Here is your client secret
  - Now from the OSMClient edit button you will be able to choose DOWNLOAD JSON to the osm-data folder
    - **Please be mindful** to apply appropiate security and permissions to the client_secret.json file

### Other notes (ymmv)
- If using mode user on pristine install, you will need to create a permissions.tsv in the osm-data folder.
  - Create an entry for your admin user something like: username@yourdomain.com<TAB>admin
  - Remember to make set the permissions correct on the file.
- Self Signed Certificate
 - **Note: Officially Signed Certificates are recommended for safety and security**
 - If you choose to use a self signed certificate you can install it in the Google Admin Console
   - Device management > Networks > Certificates
   - Choose the OU you wish to modify
   - Upload your web server Self Signed Certificate
     - (Enable) Use this certificate as an HTTPS certificate authority

 - During testing if you set the subjectAltName="DNS:osm.xxx,DNS:osm" for your
 certificate creation there were no extra settings required. Otherwise the next
 setting seemed to make Chrome not complain about the certificate
   - Device management > Chrome > User Settings
    - Choose the OU you wish to modify
    - Scroll down to Security section
    - Locate the Local Trust Anchors Certificates
    - Local Anchors Common Name Fallback
     - Allow

FIXME

### DB Setup
one option for password gen: cat /proc/sys/kernel/random/uuid

```
DROP DATABASE IF EXISTS osm;
CREATE DATABASE osm;

DROP USER IF EXISTS osm@localhost;
CREATE USER 'osm'@'localhost' IDENTIFIED BY '35e96f8d-9ec9-4a46-8f3b-dc9c438f50ac';
GRANT ALL PRIVILEGES ON osm.* TO 'osm'@'localhost';
FLUSH PRIVILEGES;
```

mysql -h localhost -u osm -p 35e96f8d-9ec9-4a46-8f3b-dc9c438f50ac < setup.sql

