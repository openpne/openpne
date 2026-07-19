<?php

namespace App\Features\Community\Exceptions;

enum CommunityActionFailure: string
{
    case AlreadyMember = 'already_member';
    case AlreadyRequested = 'already_requested';
    case NotMember = 'not_member';
    case NotPending = 'not_pending';
    case AdminCannotQuit = 'admin_cannot_quit';
    case NotManager = 'not_manager';
    case NotAdmin = 'not_admin';
    case NotSubAdmin = 'not_sub_admin';
    case TargetNotPlainMember = 'target_not_plain_member';
    case TargetIsPendingAdmin = 'target_is_pending_admin';
    case TransferAlreadyRequested = 'transfer_already_requested';
    case NoTransferPending = 'no_transfer_pending';
    case CategoryNotAllowed = 'category_not_allowed';
}
