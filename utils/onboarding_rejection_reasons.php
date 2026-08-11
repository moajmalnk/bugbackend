<?php
/**
 * Why: Canonical HR rejection reasons so verify API, email, WhatsApp, and push
 * share the same labels and employee next-step copy.
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
 * @return array{code: string, label: string, action: string}|null
 */
function br_resolve_onboarding_rejection_reason(string $code, ?string $note = null): ?array
{
    $code = strtolower(trim($code));
    $catalog = br_onboarding_rejection_reasons();
    if ($code === '' || !isset($catalog[$code])) {
        return null;
    }
    $entry = $catalog[$code];
    $note = trim((string) $note);
    if (!empty($entry['requires_note']) && $note === '') {
        return null;
    }
    $action = (string) $entry['action'];
    if ($note !== '') {
        $action = $action . ' HR note: ' . $note;
    }
    return [
        'code' => $code,
        'label' => (string) $entry['label'],
        'action' => $action,
    ];
}
