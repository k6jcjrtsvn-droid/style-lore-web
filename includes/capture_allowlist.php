<?php
/**
 * Who may switch off the Android app's screen-capture block.
 *
 * The Android app sets FLAG_SECURE, so the OS refuses to screenshot or
 * screen-record it — that is what stops anyone capturing another member's
 * Community photos. api/capture_allowed.php lets a named account clear that
 * flag for itself, so the people who make our own marketing footage can
 * record the real app.
 *
 * Entries are the SHA-256 of the account's email, lowercased and trimmed —
 * not the address itself, so no one's email sits in the repo. Add someone:
 *
 *     printf '%s' "person@example.com" | sha256sum
 *
 * Removing a hash revokes it on their next app launch. Keep this list tiny
 * and keep a name against every line.
 */
const CAPTURE_ALLOWED_EMAIL_HASHES = [
    // Jayne (co-owner) — Pinterest and social footage. Added 2026-10-23.
    '0d5e333df2c5ffbb37849ec16bed8b804d719fb9104fd650d80e4f251e3e5ff4',
];
