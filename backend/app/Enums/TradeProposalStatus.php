<?php

namespace App\Enums;

/**
 * Laravel-only status layered on top of the AI engine's risk result: tracks
 * the human approval workflow for a trade_proposals row.
 */
enum TradeProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Executed = 'executed';
}
