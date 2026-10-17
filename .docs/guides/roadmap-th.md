# คู่มือปิดงาน Roadmap

เอกสารนี้เป็นฉบับภาษาไทยของสถานะและแผนปิดงานใน [Roadmap](../operations/roadmap.md) โดยจัดลำดับตาม dependency เพื่อรักษา invariant หลักของระบบ: หนึ่ง invoice จ่ายได้เพียงครั้งเดียว และจะเป็น `PAID` ได้หลัง settlement ยืนยันแล้วเท่านั้น

## สถานะปัจจุบัน ณ 14 ตุลาคม 2026

Settlement core พร้อมสำหรับ local demo แล้ว: `php artisan test` ผ่าน 52 tests / 278 assertions และข้าม PostgreSQL-only test 1 รายการ; `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --test` และ `npm run types:check` ผ่าน. Fake provider, state machine, idempotency, webhook replay protection, recovery และ SolidInvoice adapter มีอยู่แล้ว. Recovery scheduler ถูกลงทะเบียนทุก 5 นาทีแบบป้องกันงานซ้อน แต่ deployment ต้องรัน Laravel scheduler ด้วย.

งาน approved-recipient และ DTO contract ปิดแล้ว: API รับ `client_id` แบบ optional, แต่ client ที่มากับ invoice เป็น authoritative source. หากส่ง `client_id` ที่ไม่ตรงกับ invoice ระบบจะปฏิเสธ request; หาก invoice ไม่มี client จึงใช้ `client_id` จาก request เป็น fallback. Recipient ที่ไม่เคยลงทะเบียนจะถูกเพิ่มเป็น inactive และต้องให้มนุษย์อนุมัติก่อนจ่าย.

## ระยะ 1: ปิดคุณภาพโค้ดและ recipient control (เสร็จแล้ว)

1. API และ `CreateSettlementCommand` รับ `client_id` แบบ optional แล้ว.
2. invoice client มีสิทธิ์สูงสุด: `client_id` ที่ขัดแย้งถูกปฏิเสธก่อนสร้าง settlement.
3. เพิ่ม test สำหรับ client ID ที่ตรงและไม่ตรงกับ invoice รวมถึง auto-register/activate recipient.
4. รัน `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --test`, `npm run types:check`, และ `php artisan test` ผ่านทั้งหมด.

เงื่อนไขผ่านแล้ว: ไม่มี static-analysis error, invoice ที่ recipient ยังไม่อนุมัติไม่สามารถ submit ได้, และ retry เดิมยังคืน settlement เดิมเสมอ.

## ระยะ 2: ยืนยันความปลอดภัยบน PostgreSQL และ Docker

1. เริ่ม local stack ด้วย `docker compose -f docker-compose.local.yml up --build`.
2. ตรวจสอบว่า Bridge ใช้ฐาน `settlement` และ SolidInvoice ใช้ฐาน `solidinvoice`; ไฟล์ `docker/init-db.sh` เตรียมฐานที่สองไว้แล้ว.
3. ตั้งค่า SolidInvoice token, สร้าง client และ invoice `pending` ที่มี `crypto_address`, แล้ว activate recipient ผ่าน dashboard/API ก่อนให้ poller ทำงาน.
4. รัน PostgreSQL-only duplicate-pressure test (AC4.2) สำเร็จแล้วเมื่อ 15 ตุลาคม 2026: 5 submissions เดียวกันสร้าง settlement เดียว. ห้ามถือว่า SQLite in-memory แทนการทดสอบ lock และ unique-constraint race ของ production database ได้.
5. เพิ่มและรัน true concurrency test ที่ใช้ 10 PHP worker processes พร้อมกันผ่าน shared barrier สำเร็จแล้วเมื่อ 16 ตุลาคม 2026. AC4.2 ยืนยันว่าได้ settlement เดียวและ `PAYMENT_SUBMITTED` event เดียว.
6. ทดสอบ `/demo/reset` บน PostgreSQL เพื่อยืนยันการลบ settlement events, webhook events และ settlements ตามลำดับที่ไม่ชน foreign key.

เงื่อนไขผ่านบางส่วน: AC4.2 ผ่านบน PostgreSQL และ true concurrency test ผ่านแล้ว. ยังต้องยืนยัน reset demo บน PostgreSQL และยืนยันว่า poller ไม่สร้าง payment ซ้ำสำหรับ invoice เดิม.

## ระยะ 3: เตรียม Arc testnet

1. สร้าง Circle developer-controlled wallet บน Arc Testnet และเติม test USDC ใน wallet สำหรับจ่าย.
2. ตั้งค่า `.env`: `SETTLEMENT_PROVIDER=arc`, `CIRCLE_API_KEY`, `CIRCLE_ENTITY_SECRET`, `ARC_WALLET_ID`, `ARC_WALLET_ADDRESS`, token/USDC address และ `ARC_WEBHOOK_SECRET`.
3. การตั้งค่า Arc ถูก fail-closed แล้ว: Arc provider จะปฏิเสธ unsigned webhook แม้เป็น local environment และ `.env` ตั้ง `SETTLEMENT_ALLOW_UNSIGNED_WEBHOOKS=false`.
4. รัน `php artisan settlements:circle-check` เพื่อทดสอบ API key, RSA-OAEP encryption และ wallet configuration ก่อนส่งเงินจริงบน testnet.
5. ส่ง invoice หนึ่งรายการ, เก็บ provider transaction ID และ tx hash, แล้วส่ง webhook ที่ลงลายมือชื่อถูกต้องเพื่อยืนยัน. ตรวจ event timeline ว่า `SUBMITTED` มาก่อน `CONFIRMED` และ invoice เป็น `PAID` หลัง confirmation เท่านั้น.
6. เพิ่ม integration test ที่ใช้ Circle sandbox/webhook contract เมื่อ credentials พร้อม. เก็บ secret ใน environment/secret manager เท่านั้น.

เงื่อนไขผ่าน: มีหลักฐาน tx hash ของ Arc testnet, webhook ที่ replay แล้วไม่ทำ reconciliation ซ้ำ, และ failed/invalid evidence ยังปล่อย invoice เป็น `UNPAID`.

## ระยะ 4: เตรียมเดโมและส่งงาน

1. ทำเดโม normal flow: `UNPAID -> SUBMITTED -> CONFIRMED -> PAID`.
2. ทำเดโม duplicate pressure: ยิง request เดียวกัน 10 ครั้งและแสดงว่ามี settlement/provider transfer เดียว.
3. ทำเดโม failure/timeout: invoice ยัง `UNPAID`, settlement เป็น `SUBMISSION_UNKNOWN`, จากนั้นรัน `php artisan settlements:recover-unknown` และยืนยันว่าใช้ provider idempotency key เดิม.
4. เปิด dashboard เพื่อแสดง append-only events และหลักฐาน transaction ที่ verify แล้ว.
5. ทดสอบ fresh clone ตาม README, ตรวจว่าไม่มี credential ใน Git, ทบทวน Docker/README, บันทึกวิดีโอสำรอง, แล้ว tag `v0.1.0`.

## หลัง MVP

ทำงานส่วนขยายหลังจาก demo path เสถียรเท่านั้น: queue/scheduler monitoring, adapters สำหรับ Invoice Ninja/ERPNext/Odoo, settlement providers เพิ่มเติม และ AI recommendation ที่ต้องมีการอนุมัติของมนุษย์ก่อน execute. งานเหล่านี้ต้องเรียกผ่าน contracts เดิม และห้ามข้าม state machine หรือ audit trail.
