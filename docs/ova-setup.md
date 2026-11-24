# Client License Prompt — Full Setup Guide

Environment: Ubuntu VM, admin user `zabbix` (existing, GUI access), `client` user (TTY-only, no GUI, no sudo except a scoped write rule). License file lives under `zabbix`'s home dir. This doc is written so it can be replayed start-to-finish on a fresh VM before turning it into an OVA template.

---

## 0. Prerequisites

```bash
sudo apt update
sudo apt install -y dialog
```

`dialog` is required for the TTY popup prompts. No GUI/X11 dependency — works over plain console/SSH TTY.

---

## 1. Create the `client` user

```bash
sudo useradd -m -s /bin/bash client
sudo passwd client
```

- `-m` creates `/home/client`
- `-s /bin/bash` gives a normal login shell
- Do **not** add `client` to `sudo`, `adm`, or any GUI/display-related group. Leave it in its own default group only.

Verify:
```bash
groups client
```
Should show only `client` — nothing else.

---

## 2. Set up the license file (owned by `zabbix`)

```bash
sudo mkdir -p /home/zabbix/zabbix-docker/license
sudo touch /home/zabbix/zabbix-docker/license/license.lic
sudo chown -R zabbix:zabbix /home/zabbix/zabbix-docker/license
sudo chmod 600 /home/zabbix/zabbix-docker/license/license.lic
```

Result: only `zabbix`/root can read the file. `client` can never read it — only write to it via the scoped sudo rule below.

---

## 3. Sudoers rule — scoped write access for `client`

```bash
sudo visudo -f /etc/sudoers.d/client-license
```

Paste:
```
client ALL=(zabbix) NOPASSWD: /usr/bin/tee /home/zabbix/zabbix-docker/license/license.lic, /usr/bin/tee -a /home/zabbix/zabbix-docker/license/license.lic
```

Save, then lock down the file permissions:
```bash
sudo chmod 440 /etc/sudoers.d/client-license
```

This lets `client` run `tee`/`tee -a` **as `zabbix`, on this one file only** — nothing else, no other user, no other path.

---

## 4. Create the license script

```bash
sudo nano /home/client/license.sh
```

Paste:

```bash
#!/bin/bash

LOCK="/home/client/.lic_saved.lock"
LIC_FILE="/home/zabbix/zabbix-docker/license/license.lic"

# Count non-empty lines
if [ -f "$LIC_FILE" ]; then
    LINE_COUNT=$(grep -cve '^\s*$' "$LIC_FILE")
else
    LINE_COUNT=0
fi

# If license already saved → create lock and exit
if [ "$LINE_COUNT" -ge 2 ]; then
    touch "$LOCK"
    exit 0
fi

# If lock exists → skip popup
if [ -f "$LOCK" ]; then
    exit 0
fi

TMP_LIC="/tmp/lic.txt"
TMP_INS="/tmp/ins.txt"

get_input() {
    local title="$1"
    local prompt="$2"
    local output="$3"

    while true; do
        dialog --title "$title" --inputbox "$prompt" 8 60 2> "$output"
        local val=$(cat "$output")
        if [ -n "$val" ]; then
            break
        else
            dialog --title "Error" --msgbox "This field cannot be empty!" 6 50
        fi
    done
}

get_input "License Key" "Enter your LICENSE KEY:" "$TMP_LIC"
LIC=$(cat "$TMP_LIC")

get_input "Instance ID" "Enter your INSTANCE ID:" "$TMP_INS"
INS=$(cat "$TMP_INS")

echo "$LIC" | sudo -u zabbix tee "$LIC_FILE" > /dev/null
echo "$INS" | sudo -u zabbix tee -a "$LIC_FILE" > /dev/null

touch "$LOCK"

dialog --title "Saved" --msgbox "License details saved!" 6 50
clear
```

Set permissions:
```bash
sudo chmod +x /home/client/license.sh
sudo chown client:client /home/client/license.sh
```

---

## 5. Trigger the script on login

```bash
sudo nano /home/client/.bash_profile
```

Add:
```bash
bash /home/client/license.sh
```

Note: only fires on **login shells** — console login, SSH login, or `su - client` (with the dash). Plain `su client` or `sudo -u client bash` (no `-l`/no dash) will skip it.

---

## 6. Boot the VM to TTY-only (no GUI on startup)

```bash
sudo systemctl set-default multi-user.target
sudo reboot
```

After reboot, the VM lands on a plain text `login:` prompt for everyone — admin included.

Admin (`zabbix`) can bring up the GUI on demand from their TTY session:
```bash
sudo systemctl start display-manager
```

`client` has no path to this — no sudo rule permits it, and they're in no relevant group.

---

## 7. Test end-to-end

```bash
sudo su - client
```

You should see the `dialog` popups: License Key → Instance ID → "License details saved!" confirmation.

Verify the write landed correctly (as `zabbix`, since `client` can't read it):
```bash
exit
sudo -u zabbix cat /home/zabbix/zabbix-docker/license/license.lic
```

Test the idempotency (should NOT prompt again on next login):
```bash
sudo su - client
```
Should return to a normal shell with no popup, since `LINE_COUNT -ge 2` now.

---

## 8. Reset before creating the OVA / template

**This step is critical** — skipping it means every VM cloned from this template will already show as "licensed."

```bash
# Wipe the license content
sudo truncate -s 0 /home/zabbix/zabbix-docker/license/license.lic

# Remove the lock so the prompt fires again on next login
sudo rm -f /home/client/.lic_saved.lock

# Clear shell history so test entries don't leak into the template
sudo -u client bash -c 'history -c; rm -f ~/.bash_history'
sudo -u zabbix bash -c 'history -c; rm -f ~/.bash_history'
```

Then do **one final verification pass** (step 7) to confirm the prompt still fires correctly — and reset again (this step) immediately after, since that final test will repopulate the file and lock. Export the OVA right after this last reset, with no further logins in between.

---

## Quick troubleshooting reference

| Symptom | Cause | Fix |
|---|---|---|
| No popup appears | `dialog` not installed | `sudo apt install -y dialog` |
| No popup appears | Stale `.lic_saved.lock` exists | `sudo rm -f /home/client/.lic_saved.lock` |
| No popup appears | `license.lic` already has ≥2 lines | `sudo truncate -s 0 /home/zabbix/zabbix-docker/license/license.lic` |
| No popup appears | Logged in without a login shell | Use `sudo su - client` (with the dash), not `su client` |
| `sudo: I'm sorry` error | `client` tried a command other than `tee` | Expected — sudo rule only permits `tee`/`tee -a` as `zabbix` |
| `ls: Permission denied` on `/home/client/...` from `zabbix` | Correct behavior | `client`'s home dir is intentionally not readable by `zabbix` |
