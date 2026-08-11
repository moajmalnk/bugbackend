<?php
/**
 * Why: Canonical HR rejection reasons so verify API, email, WhatsApp, and push
 * share the same labels and employee next-step copy.
 * Codes may be single or multi (comma-separated) when HR selects several issues.
 */

/**
 * @return array<string, array{label: string, action: string, requires_note?: bool}>
 */
function br_onboarding_rejection_reasons(): array
{
    return [
        'profile_photo_mismatch' => [
            'label' => 'Profile photo does not match the employee',
            'action' => 'Please re-upload a clear photo of yourself only (Profile → Change photo).',
        ],
        'aadhaar_unclear' => [
            'label' => 'Aadhaar scan is unclear or unreadable',
            'action' => 'Please re-upload a clear, complete Aadhaar scan and resubmit.',
        ],
        'pan_missing' => [
            'label' => 'PAN document missing or invalid',
            'action' => 'Please upload a valid PAN scan and resubmit onboarding.',
        ],
        'banking_mismatch' => [
            'label' => 'Banking details are incorrect',
            'action' => 'Please correct account holder, account number, or IFSC and resubmit.',
        ],
        'address_incomplete' => [
            'label' => 'Address details incomplete or incorrect',
            'action' => 'Please update your address and resubmit onboarding.',
        ],
        'documents_incomplete' => [
            'label' => 'Required documents are incomplete',
            'action' => 'Please complete the missing documents and resubmit for review.',
        ],
        'other' => [
            'label' => 'Other (see note from HR)',
            'action' => 'Please follow the note from HR and update your profile / documents.',
            'requires_note' => true,
        ],
    ];
}

/**
 * Normalize incoming reason payload to a unique list of catalog codes.
 *
 * @param string|array|null $codes
 * @return list<string>
 */
function br_normalize_onboarding_rejection_codes($codes): array
{
    if (is_string($codes)) {
        $parts = preg_split('/[\s,;|]+/', $codes) ?: [];
    } elseif (is_array($codes)) {
        $parts = $codes;
    } else {
        return [];
    }

    $catalog = br_onboarding_rejection_reasons();
    $out = [];
    foreach ($parts as $part) {
        $code = strtolower(trim((string) $part));
        if ($code === '' || !isset($catalog[$code])) {
            continue;
        }
        if (!in_array($code, $out, true)) {
            $out[] = $code;
        }
    }
    return $out;
}

/**
 * Resolve one or more rejection reasons into storage + notification copy.
 *
 * @param string|array|null $codes
 * @return array{code: string, codes: list<string>, label: string, action: string}|null
 */
function br_resolve_onboarding_rejection_reason($codes, ?string $note = null): ?array
{
    $normalized = br_normalize_onboarding_rejection_codes($codes);
    if ($normalized === []) {
        return null;
    }

    $catalog = br_onboarding_rejection_reasons();
    $note = trim((string) $note);
    $labels = [];
    $actions = [];
    $requiresNote = false;

    foreach ($normalized as $code) {
        $entry = $catalog[$code];
        if (!empty($entry['requires_note'])) {
            $requiresNote = true;
        }
        $labels[] = (string) $entry['label'];
        $actions[] = (string) $entry['action'];
    }

    if ($requiresNote && $note === '') {
        return null;
    }

    $action = implode(' ', array_unique($actions));
    if ($note !== '') {
        $action = $action . ' HR note: ' . $note;
    }

    return [
        'code' => implode(',', $normalized),
        'codes' => $normalized,
        'label' => implode(' · ', $labels),
        'action' => $action,
    ];
}
