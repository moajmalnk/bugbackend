<?php
/**
 * Google OAuth scopes requested by the Connect Google flow (BugDocs, BugSheets, BugMeet).
 *
 * Why: Google verification approves an exact scope list on the OAuth consent
 * screen. Requesting anything not declared there brings back the
 * "Google hasn't verified this app" warning, so every Google client must use this list.
 */
const BR_GOOGLE_OAUTH_SCOPES = [
    'https://www.googleapis.com/auth/userinfo.email',
    // BugDocs / BugSheets: create/edit only files created or opened with BugRicer.
    // Why: Google verification requires minimum scopes; Docs/Sheets APIs accept drive.file.
    'https://www.googleapis.com/auth/drive.file',
    // BugMeet only inserts/reads/deletes events on the primary calendar.
    'https://www.googleapis.com/auth/calendar.events',
];
