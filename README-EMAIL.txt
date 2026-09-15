# ── Email Verification Setup Guide — RaktSethu ──────────────────────

## Why EmailJS (not PHP mail)?

InfinityFree (and most free hosts) block all outbound email and HTTP
from the server — PHP's mail() function and PHPMailer both silently fail.
EmailJS solves this by sending the email from the USER'S BROWSER via
JavaScript. The security-critical parts (token generation, validation,
account activation) all stay server-side in PHP. EmailJS just delivers
the message containing the link.

Free tier: 200 emails/month — more than enough for a capstone project.

## Step-by-step setup

### 1. Create an EmailJS account (free)
   - Go to https://www.emailjs.com → Sign up
   - Confirm your own email

### 2. Add an Email Service (your Gmail)
   - Dashboard → Email Services → Add New Service
   - Choose "Gmail"
   - Connect your Gmail account (the one that will SEND the emails)
   - Copy the **Service ID** (looks like `service_xxxxxxx`)

### 3. Create an Email Template
   - Dashboard → Email Templates → Create New Template
   - Set these fields:
     - **To Email:** `{{to_email}}`
     - **Subject:** `Verify your RaktSethu account`
   - In the **Content** box, paste this:

   ┌──────────────────────────────────────────────────────────────┐
   │ Hi {{to_name}},                                              │
   │                                                              │
   │ Welcome to RaktSethu! Please verify your email address to    │
   │ activate your account.                                       │
   │                                                              │
   │ Click the link below to verify:                              │
   │ {{verification_link}}                                        │
   │                                                              │
   │ If you did not create an account, you can safely ignore this │
   │ email.                                                       │
   │                                                              │
   │ — RaktSethu Team                                             │
   └──────────────────────────────────────────────────────────────┘

   - Click Save
   - Copy the **Template ID** (looks like `template_xxxxxxx`)

### 4. Get your Public Key
   - Dashboard → Account → General → API Keys
   - Copy the **Public Key** (looks like `xxxxxxxxxxxxx`)

### 5. Fill in the config in verify_notice.php
   Open `auth/verify_notice.php` and replace these three lines near the top:

       const EMAILJS_PUBLIC_KEY  = 'YOUR_PUBLIC_KEY';     ← paste your key
       const EMAILJS_SERVICE_ID  = 'YOUR_SERVICE_ID';     ← paste service ID
       const EMAILJS_TEMPLATE_ID = 'YOUR_TEMPLATE_ID';   ← paste template ID

### 6. Run the database migration
   In phpMyAdmin (InfinityFree control panel):
   - Select your database
   - Click "SQL" tab
   - Paste the contents of `database/migration_email_verification.sql`
   - Click "Go"

   This adds three columns to the users table and marks all existing
   accounts (including your admin) as already verified — nobody gets
   locked out.

### 7. Upload the files
   Upload these 4 files to your host (replacing existing ones):
   - auth/register.php        (generates token on signup)
   - auth/login.php           (blocks unverified accounts)
   - auth/verify_notice.php   (NEW — "check your email" page)
   - auth/verify_email.php    (NEW — activates account from link)

### 8. Test it
   - Register a new account with a real email you can check
   - You should see the "Verify Your Email" page
   - Click "Send Verification Email"
   - Check your inbox, click the link in the email
   - You should be redirected to login with "Email verified!" message
   - Log in successfully

## How the security works

   ┌─────────────────────────────────────────────────────────────┐
   │  1. User fills registration form                             │
   │  2. PHP generates a 64-char random token (server-side)       │
   │  3. PHP stores token in DB with emailVerified=0              │
   │  4. User's BROWSER sends email via EmailJS (token in link)   │
   │  5. User clicks link in email → verify_email.php             │
   │  6. PHP validates token against DB (server-side)             │
   │  7. PHP checks token age (< 24 hours)                       │
   │  8. PHP sets emailVerified=1, clears token                   │
   │  9. User can now log in                                      │
   └─────────────────────────────────────────────────────────────┘

   The token cannot be guessed (32 random bytes = 2^256 possibilities).
   The token expires after 24 hours.
   The email is sent from the browser, so server email-blocking doesn't matter.
   The activation is validated server-side, so the browser's role is
   purely a delivery channel — tampering with the JS cannot bypass
   verification.

## Files in this zip

   auth/register.php         — patched: generates token, redirects to verify_notice
   auth/login.php           — patched: blocks unverified accounts, offers resend
   auth/verify_notice.php   — NEW: "check your email" page with EmailJS send button
   auth/verify_email.php    — NEW: validates token from URL, activates account
   database/migration_email_verification.sql — adds columns, marks existing users verified
   README-EMAIL.txt         — this file
