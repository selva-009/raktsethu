<?php
/**
 * config/email.php — Central EmailJS configuration (ONE place to edit).
 *
 * WHY EmailJS: InfinityFree (and most free hosts) block all outbound
 * email from the server, so PHP mail() silently fails. EmailJS sends
 * the email from the USER'S BROWSER via JavaScript. The security-
 * critical parts (token generation + validation) stay server-side.
 *
 * SETUP (full guide with screenshots-level detail in README-EMAIL.txt):
 *   1. Free account at https://www.emailjs.com (200 emails/month)
 *   2. Email Services → Add "Gmail" → copy the Service ID
 *   3. Email Templates → create TWO templates (verification + password
 *      reset — exact content in README-EMAIL.txt) → copy both Template IDs
 *   4. Account → General → copy the Public Key
 *
 * Then replace the four placeholder values below. Every page that sends
 * email (auth/verify_notice.php, auth/reset_sent.php) includes this
 * file, so the keys only ever need filling in ONCE.
 */

const EMAILJS_PUBLIC_KEY       = 'YOUR_PUBLIC_KEY';
const EMAILJS_SERVICE_ID       = 'YOUR_SERVICE_ID';
const EMAILJS_TEMPLATE_ID      = 'YOUR_TEMPLATE_ID';        // email verification template
const EMAILJS_RESET_TEMPLATE_ID = 'YOUR_RESET_TEMPLATE_ID'; // password reset template
