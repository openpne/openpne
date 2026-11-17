<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The key names are literals because their enum cases are gone, and a migration must keep meaning
 * what it meant on the day it ran.
 */
return new class extends Migration
{
    private const SITE_KEYS = ['default_look', 'selectable_looks'];

    private const MEMBER_KEY = 'preferred_look';

    public function up(): void
    {
        DB::table('sns_settings')->whereIn('key', self::SITE_KEYS)->delete();
        DB::table('member_preferences')->where('key', self::MEMBER_KEY)->delete();
    }

    /** The choices are not kept anywhere, so there is nothing to put back. */
    public function down(): void {}
};
