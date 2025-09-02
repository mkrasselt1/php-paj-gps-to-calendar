# Google Calendar Integration Setup
#
# Um Google Calendar zu aktivieren:
# 1. Gehe zu https://console.developers.google.com/
# 2. Erstelle ein neues Projekt oder wähle ein bestehendes
# 3. Aktiviere die Google Calendar API
# 4. Erstelle OAuth 2.0 Credentials (Desktop Application)
# 5. Lade die credentials.json herunter und speichere sie als config/google-credentials.json
# 6. Setze calendar.google_calendar.enabled auf true in config/config.yaml
# 7. Optional: Ändere calendar.google_calendar.calendar_id auf die gewünschte Calendar ID
#
# Beispiel credentials.json:
# {
#   "web": {
#     "client_id": "your-client-id.apps.googleusercontent.com",
#     "project_id": "your-project-id",
#     "auth_uri": "https://accounts.google.com/o/oauth2/auth",
#     "token_uri": "https://oauth2.googleapis.com/token",
#     "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
#     "client_secret": "your-client-secret",
#     "redirect_uris": ["urn:ietf:wg:oauth:2.0:oob"]
#   }
# }
