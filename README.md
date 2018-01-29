# Open Screen Monitor (open-screen-monitor)
## An open-source screen monitoring tool for Chrome.
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
- Configure OAuth access to your Organizational Unit of your domain
 - Go to  https://console.developers.google.com/
 - Create a new API Project with name OpenScreenMonitor
 - Once created open the OSMOAuth project and enable the Admin SDK
 - Create the APIs Credentials of type OAuth client ID
  - Configure OAuth consent screen: yourDomain - OpenScreenMonitor
  - Application type: Web application
  - Set Authorized JavaScript origins and Authorized redirect URIs
  - After you choose Create you should get an OAuth Client screen with the following information:
   - Here is your client ID
   - Here is your client secret
  - Now from the OSMClient edit button you will be able to choose DOWNLOAD JSON

FIXME
