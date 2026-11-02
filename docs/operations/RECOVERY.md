# Account Recovery & Password Reset Guide

Nearby Café (VenueVue) uses a secure, modern **6-digit email verification code** workflow for password resets, eliminating the need for terminal commands or paper codes.

---

## 1. Setting Up Your Recovery Email

1. Sign in as the Store Owner.
2. Open **Management → Account security**.
3. Under **Owner Recovery Email & Store Profile**:
   - Verify or enter your **Recovery Email Address** (e.g., `owner@example.com` or your Gmail address).
   - Enter your current Owner password to authorize the change.
   - Click **Save profile & recovery email**.
4. To verify email delivery, click **Send test 6-digit code** to confirm that reset codes reach your inbox.

---

## 2. Connecting Gmail SMTP for Online Email Delivery

To allow VenueVue to dispatch real emails directly to your Gmail inbox:

1. Open [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) on your Google Account:
   - Ensure **2-Step Verification** is turned on.
   - Create an App Password with the name *Nearby Café*.
   - Copy the 16-character code (e.g., `abcd efgh ijkl mnop`).
2. In Nearby Café, go to **Management → Account security** $\rightarrow$ **Email Delivery & Gmail SMTP Setup**:
   - **Sender Gmail Address**: Enter your Gmail address.
   - **Google 16-Character App Password**: Paste your 16-character code.
   - Enter your current Owner password.
   - Click **Connect & Test Email Delivery**.
3. VenueVue will test the TLS connection to `smtp.gmail.com:587`, save the configuration in `storage/smtp.json`, and dispatch a live test code to your inbox.

---

## 3. How to Reset a Forgotten Password

When an Owner or staff member forgets their password:

1. On the login screen, click **Forgot password?** (or navigate to `/#/forgot-password`).
2. **Step 1: Enter Email**:
   - Type your registered email address and click **Send verification code**.
   - If the email is not registered in the database, the system will explicitly alert:  
     `"No account is registered with this email. Please check your spelling."`
   - If registered, the system generates a cryptographically secure 6-digit code with a 15-minute expiration time and sends it to your email inbox.
3. **Step 2: Enter Code & New Password**:
   - Open your email inbox (and check your Spam folder if needed).
   - Enter the 6-digit verification code.
   - Type your **New password** and **Confirm new password** ($\ge 12$ characters).
   - Click **Reset password & sign in**.
4. **Step 3: Success**:
   - All previous active sessions for the account are immediately revoked.
   - You can now sign in with your new password.

---

## 4. Security Guarantees & Protection

- **No On-Screen Code Display**: Verification codes are **never** rendered on the webpage or returned in API responses. A malicious third party visiting the reset page cannot see or intercept the code without accessing the recipient's private email inbox.
- **Brute-Force & Rate Limiting**: The recovery endpoint enforces strict limits (at most 6 attempts per 15-minute window per email and IP). Repeated invalid attempts temporarily lock the request bucket.
- **Audit Logging**: Every code generation and reset event is recorded in the immutable audit log (`RECOVERY_CODE_SENT`, `PASSWORD_RESET`).
- **Server Outbox Log**: For local development or server administrator verification, every dispatched code is also logged on the server filesystem at `storage/mail-outbox.log` (accessible only to users with server terminal/file access).

---

## 5. Owner Resets a Barista Password

Staff/Barista members do not need to use email recovery:
1. The Owner signs into **Management → Staff** or **Management → Account security**.
2. Under **Reset a Barista password**:
   - Select the Barista account.
   - Enter the current Owner password.
   - Enter and confirm the new Barista password ($\ge 12$ characters).
   - Click **Reset Barista password**.
3. The Barista is signed out on all devices and can immediately sign in with the new password. Their saved drafts and offline orders remain safe on their device.
