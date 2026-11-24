# License Admin UI

React web app for Identiqa staff to manage NMS licenses. It talks to the
License API in [`../backend`](../backend/).

## Features

- Dashboard with license statistics and overview
- Create licenses (client details, application, type, expiry)
- List, search, update and delete licenses
- Check a single license key

## Tech stack

React 19 · Create React App (`react-scripts` 5) · React Router 7 · Axios

## Project layout

```
public/                 Static assets and index.html
src/
  App.js                Routes
  components/
    ApiClient.js        All API calls (base URL from REACT_APP_API_BASE)
    Login.js            Login screen
    Dashboard.js, Overview.js, LicenseStats.js
    CreateLicense.js, CreateLicenseForm.js
    AllLicenses.js, LicenseList.js
    DigitalClock.js
  images/               Logos
```

## Configuration

Copy `.env.example` to `.env` and set the API base URL:

| Variable | Description |
|---|---|
| `REACT_APP_API_BASE` | License API base, e.g. `https://<lambda-function-url>/backend_api` |

Create React App bakes `REACT_APP_*` values into the build, so set the variable
before running `npm run build`.

## Development

```bash
npm install
npm start          # http://localhost:3000
npm test
```

## Production build

```bash
npm run build      # outputs to build/
```

Serve `build/` from any static host (e.g. Azure Static Web Apps, S3 + CloudFront,
or Nginx). Configure the host to send every route to `index.html`.

## API calls used

| Call | Endpoint |
|---|---|
| Check license | `GET /check-license/:licenseKey` |
| Create license | `POST /generate-license` |
| List licenses | `GET /all-licenses` |
| Update license | `PUT /update-license/:licenseKey` |
| Delete license | `DELETE /delete-license/:licenseKey` |

## Security

The login screen checks credentials inside the browser, and the API admin
endpoints have no authentication. Read items S4 and S5 in
[../../docs/known-issues.md](../../docs/known-issues.md) before exposing this app publicly.
