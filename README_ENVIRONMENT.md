# Environment Configuration

This project uses environment variables for sensitive configuration like OAuth credentials.

## Setup Instructions

### 1. Create Environment File

Create a `.env` file in the backend root directory with the following content:

```bash
# Google OAuth Configuration
GOOGLE_CLIENT_ID=your_google_client_id_here
GOOGLE_CLIENT_SECRET=your_google_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost/BugRicer/backend/api/oauth/callback

# Database Configuration (if needed)
DB_HOST=localhost
DB_NAME=bugricer_db
DB_USER=root
DB_PASS=
```

### 2. Get Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the Google Docs API and Google Drive API
4. Go to "Credentials" → "Create Credentials" → "OAuth 2.0 Client IDs"
5. Set the redirect URI to: `http://localhost/BugRicer/backend/api/oauth/callback`
6. Copy the Client ID and Client Secret to your `.env` file

### 3. Security Notes

- **Never commit the `.env` file to version control**
- The `.env` file is already added to `.gitignore`
- For production, set these as system environment variables instead of using a file
- Rotate your OAuth credentials if they were ever exposed

### 4. Production Deployment

For production environments, set the environment variables directly on your server:

```bash
export GOOGLE_CLIENT_ID="your_production_client_id"
export GOOGLE_CLIENT_SECRET="your_production_client_secret"
export GOOGLE_REDIRECT_URI="https://yourdomain.com/backend/api/oauth/callback"
```

## Troubleshooting

If you get an error about missing OAuth credentials, make sure:

1. The `.env` file exists in the backend root directory
2. The environment variables are set correctly
3. The Google OAuth credentials are valid and active
4. The redirect URI matches exactly in both your code and Google Console
