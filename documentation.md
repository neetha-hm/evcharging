============================================================
QR-BASED EV ENERGY TRACKING SYSTEM
NON-DEVELOPER HANDOVER DOCUMENT
============================================================

Audience:
- Operations team
- Admins
- Facility managers
- Support staff

------------------------------------------------------------
1. WHAT THIS SYSTEM DOES
------------------------------------------------------------

This system allows users to:
- Scan a QR code on an EV charging device
- Automatically start an energy usage session
- Track live energy consumption
- Stop charging and calculate total usage & cost

It ensures:
- One user per device at a time
- Accurate energy tracking
- Automatic session start and stop
- Secure login handling
- Full logging for audit and billing

------------------------------------------------------------
2. MAIN PARTS OF THE SYSTEM
------------------------------------------------------------

There are TWO main parts:

1) QR Scanner (User-facing)
2) Energy & Device Backend (Automatic)

Users only interact with the QR scanner.
Everything else runs in the background.

------------------------------------------------------------
3. HOW A NORMAL USER FLOW WORKS
------------------------------------------------------------

STEP 1: User scans QR code
- Camera opens in browser
- QR code is detected automatically

STEP 2: Login check
- If user is not logged in:
  - System sends them to login
  - After login, scan continues automatically

STEP 3: Device validation
- System checks if the QR code is valid
- System checks if device is already in use

STEP 4: Device communication
- System contacts the charging device
- Requests live energy reading
- Starts charging session

STEP 5: Charging session
- Energy usage is tracked live
- Data is stored automatically

STEP 6: Charging ends
- System calculates:
  - Energy used
  - Cost
- Session is closed
- User can view usage

------------------------------------------------------------
4. WHAT ADMINS NEED TO KNOW
------------------------------------------------------------

A. Devices
----------
- Each QR code represents one physical device
- A device can only be used by ONE user at a time

B. Sessions
-----------
- Each charging session is saved automatically
- Sessions cannot overlap for the same device

C. Billing
----------
- Cost is calculated automatically
- Formula:
  Energy Used × Rate (configured in system)

------------------------------------------------------------
5. EMAILS & NOTIFICATIONS
------------------------------------------------------------

Currently:
- System logs all events
- Can be extended to send alerts or receipts

------------------------------------------------------------
6. IF SOMETHING GOES WRONG
------------------------------------------------------------

If users say:
- “QR scan didn’t work”
  → Check camera permission & HTTPS

- “Device already in use”
  → Another user is currently charging

- “Energy not updating”
  → Check backend listener is running

- “Login loop”
  → Clear browser session and retry scan

------------------------------------------------------------
7. WHAT ADMINS SHOULD NEVER DO
------------------------------------------------------------

- Do NOT manually edit charging sessions
- Do NOT restart devices mid-session
- Do NOT stop backend service abruptly
- Do NOT reuse QR codes for multiple devices

------------------------------------------------------------
8. DAILY OPERATION CHECKLIST
------------------------------------------------------------

✔ Backend listener running  
✔ Devices publishing data  
✔ QR scanner page accessible  
✔ Database responding  

------------------------------------------------------------
END OF HANDOVER DOCUMENT
------------------------------------------------------------
