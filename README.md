# Arc Settlement Bridge

A reliable settlement layer between invoicing systems
and programmable payment networks.

## Problem

An invoicing system can record a payment without proving
that the underlying funds actually moved.

Network retries may also cause the same payment request
to be submitted more than once.

## Goal

Pay an invoice exactly once and only mark it as paid
after the settlement is confirmed.

## Core guarantees

1. One invoice payment → one settlement
2. Duplicate requests never create duplicate payments
3. Invoice is never marked PAID before settlement confirmation
4. Every settlement transition is auditable