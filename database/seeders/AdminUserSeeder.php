<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $hospital = Hospital::where('code', env('DEFAULT_HOSPITAL_CODE', 'CK'))->firstOrFail();

        $admin = User::updateOrCreate(
            ['email' => 'admin@ck-mems.local'],
            [
                'hospital_id'    => $hospital->id,
                'username'       => 'Administrator',
                'name'           => 'Administrator',
                'full_name'      => 'ผู้ดูแลระบบ CK-MEMS',
                'employee_code'  => 'ADMIN001',
                'password'       => Hash::make('admin1234'),
                'is_active'      => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles(['admin']);

        $this->command->info('Admin user: username=Administrator / admin1234');
    }
}
