<?php

namespace App\Services;

/**
 * Wix Contacts API integration.
 * TODO: Implement real Wix REST API call to upsert contact.
 * See: https://dev.wix.com/docs/rest/contacts/contacts/contacts-v2/upsert-contact
 */
class WixContactsService
{
    public function upsertContact(string $email, array $payload): ?string
    {
        // TODO: Call Wix Contacts API with app instance token
        // 1. Resolve Wix API base URL from site ID
        // 2. POST /contacts/v2/contacts with member payload
        // 3. Return contact ID or null on failure
        return null;
    }
}
