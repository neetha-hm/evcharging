============================================================
QR + MQTT + ENERGY TRACKING SYSTEM
DEVELOPER MEMORY DOCUMENT
============================================================

Read this if:
- You forgot why this exists
- You’re debugging after years
- You need to extend or refactor safely

------------------------------------------------------------
1. WHY I BUILT THIS
------------------------------------------------------------

Goal:
Create a REAL-TIME, DEVICE-DRIVEN energy tracking system
with ZERO manual steps for users.

Key requirements:
- QR-based pairing
- Login-safe flow
- MQTT device communication
- Fault-tolerant backend
- Accurate billing
- Long-running listener without DB failures

------------------------------------------------------------
2. HIGH-LEVEL ARCHITECTURE
------------------------------------------------------------

BROWSER
  ↓ (QR scan)
Drupal Controller
  ↓ (MQTT publish)
DEVICE
  ↓ (MQTT response)
Drupal MQTT Listener (Drush)
  ↓
Drupal DB (energy sessions)
  ↓
User-facing views

------------------------------------------------------------
3. MODULE RESPONSIBILITIES
------------------------------------------------------------

MODULE: qr_scanner_simple
------------------------
Purpose:
- Camera scanning
- Login-safe QR resume
- Device availability checks
- Kick-start device communication

Key ideas:
- Anonymous users are redirected safely
- QR value is preserved across login
- No scan state is lost
- Frontend scanning is continuous & robust

Never change lightly:
- Session handling logic
- Resume-after-login flow

------------------------------------------------------------

MODULE: user_api
----------------
Purpose:
- MQTT backbone
- Energy session lifecycle
- DB reliability in long-running process

This is the CORE of the system.

------------------------------------------------------------
4. MQTT LISTENER (MOST IMPORTANT PART)
------------------------------------------------------------

Runs as:
  drush user-api:mqtt-listen

Design decisions:
- Runs forever
- Auto-reconnects MQTT
- Keeps DB alive manually
- Restarts itself on fatal DB loss

Key protections:
- PID file to prevent duplicates
- Heartbeat to broker
- DB keepalive every 5 minutes
- Forced DB reconnection on failure

If this dies → system stops working.

------------------------------------------------------------
5. DEVICE MESSAGE FLOW
------------------------------------------------------------

Statuses handled:

MATCH
- Device paired
- No DB write yet

HIGH_CURRENT
- Start charging
- Create new session node
- Save initial energy

CHARGING
- Session remains active

LOW_CURRENT / OVERCURRENT
- End charging
- Calculate:
  final energy
  energy used
  amount
- Close session

SYSTEM / HEARTBEAT
- Ignored intentionally

------------------------------------------------------------
6. DATA MODEL (IMPORTANT MEMORY)
------------------------------------------------------------

Each charging session is:
- One node
- One user
- One device
- One continuous lifecycle

Fields track:
- Initial energy
- Final energy
- Consumed energy
- Amount charged
- Plug-in time
- Plug-out time

Never reuse sessions.
Never merge sessions.

------------------------------------------------------------
7. WHY SO MUCH DB RECONNECT LOGIC
------------------------------------------------------------

Reason:
- Drush command runs for days/weeks
- MySQL WILL drop idle connections
- Drupal does NOT auto-heal DB in CLI

Solution:
- Manual SELECT 1 pings
- Forced reconnect on failure
- Exit process on fatal DB loss
- External supervisor restarts command

This is intentional and critical.

------------------------------------------------------------
8. LOGIN + QR RESUME LOGIC
------------------------------------------------------------

Hard problem solved:
- QR scan happens BEFORE login
- Drupal login destroys session
- QR value would be lost

Solution:
- Store QR in session
- Pass QR via URL on login
- Restore QR after login
- Resume scan automatically

DO NOT simplify this.
It breaks silently.

------------------------------------------------------------
9. CACHING STRATEGY
------------------------------------------------------------

Aggressive cache invalidation used because:
- Energy data must be REAL-TIME
- Stale views are unacceptable

Caches invalidated:
- Node
- Node list
- Views
- Node type views

Correctness > performance.

------------------------------------------------------------
10. IF YOU DEBUG THIS IN FUTURE
------------------------------------------------------------

Order of checks:
1. Is MQTT listener running?
2. Is broker reachable?
3. Are devices publishing?
4. Are DB connections alive?
5. Are session nodes created?
6. Are statuses changing correctly?

Logs to check:
- user_api
- qr_scanner
- mail_man / fallback logs

------------------------------------------------------------
11. WHAT NOT TO CHANGE CASUALLY
------------------------------------------------------------

❌ MQTT topic names  
❌ Status strings  
❌ Session lifecycle order  
❌ DB reconnect logic  
❌ QR resume flow  

These are tightly coupled.

------------------------------------------------------------
12. FINAL MEMORY NOTE
------------------------------------------------------------

This system is:
- Event-driven
- Device-authoritative
- Failure-aware
- Long-running by design

If something looks “over-engineered”:
It probably broke once already.

------------------------------------------------------------
END OF DEVELOPER DOCUMENT
------------------------------------------------------------
