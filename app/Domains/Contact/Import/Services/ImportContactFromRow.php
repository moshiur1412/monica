<?php

namespace App\Domains\Contact\Import\Services;

use App\Domains\Contact\ManageContact\Services\CreateContact;
use App\Domains\Contact\ManageContactInformation\Services\CreateContactInformation;
use App\Models\ContactInformationType;

class ImportContactFromRow
{
    public function import(array $row, array $context): void
    {
        $accountId = $context['account_id'];
        $vaultId = $context['vault_id'];
        $authorId = $context['author_id'];

        $contact = (new CreateContact)->execute([
            'account_id' => $accountId,
            'vault_id' => $vaultId,
            'author_id' => $authorId,
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'listed' => true,
        ]);

        if (! empty($row['email'])) {
            $emailType = ContactInformationType::where('account_id', $accountId)
                ->where('type', 'email')
                ->first();

            if ($emailType) {
                (new CreateContactInformation)->execute([
                    'account_id' => $accountId,
                    'vault_id' => $vaultId,
                    'author_id' => $authorId,
                    'contact_id' => $contact->id,
                    'contact_information_type_id' => $emailType->id,
                    'data' => $row['email'],
                ]);
            }
        }

        if (! empty($row['phone'])) {
            $phoneType = ContactInformationType::where('account_id', $accountId)
                ->where('type', 'phone')
                ->first();

            if ($phoneType) {
                (new CreateContactInformation)->execute([
                    'account_id' => $accountId,
                    'vault_id' => $vaultId,
                    'author_id' => $authorId,
                    'contact_id' => $contact->id,
                    'contact_information_type_id' => $phoneType->id,
                    'data' => $row['phone'],
                ]);
            }
        }
    }
}
