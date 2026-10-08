<?php
namespace App\Models;

use App\Models\TestUser;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

class Admin extends TestUser
{
    #[Column(ColumnType::Boolean, default: false)]
    public private(set) bool $can_reset_passwords;

    #[Column(ColumnType::Boolean, default: false)]
    public private(set) bool $can_lock_accounts;

    #[Column(ColumnType::String, length: 255, nullable: true)]
    public private(set) ?string $notes;


    public function __construct(
        string $email,
        string $password_hash,
        string $full_name,
        bool $can_reset_passwords,
        bool $can_lock_accounts,
        ?string $notes = null
    ) {
        parent::__construct($email, $password_hash, $full_name);

        $this->can_reset_passwords = $can_reset_passwords;
        $this->can_lock_accounts = $can_lock_accounts;
        $this->notes = $notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }
}
