# คู่มือปิดงาน Roadmap

เอกสารนี้เป็นฉบับภาษาไทยของสถานะและแผนปิดงานใน [Roadmap](../operations/roadmap.md) โดยจัดลำดับตาม dependency เพื่อรักษา invariant หลักของระบบ: หนึ่ง invoice จ่ายได้เพียงครั้งเดียว และจะเป็น `PAID` ได้หลัง settlement ยืนยันแล้วเท่านั้น

## สถานะปัจจุบัน ณ 17 ตุลาคม 2026

Settlement core พร้อมสำหรับ local demo แล้ว: `php artisan test` ผ่าน 63 tests / 317 assertions และข้าม PostgreSQL-only test 1 รายการ; `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --test` และ `npm run types:check` ผ่าน. Fake provider, state machine, idempotency, webhook replay protection, recovery และ SolidInvoice adapter มีอยู่แล้ว. Recovery scheduler ถูกลงทะเบียนทุก 5 นาทีแบบป้องกันงานซ้อน แต่ deployment ต้องรัน Laravel scheduler ด้วย.

งาน approved-recipient และ DTO contract ปิดแล้ว: API รับ `client_id` แบบ optional, แต่ client ที่มากับ invoice เป็น authoritative source. หากส่ง `client_id` ที่ไม่ตรงกับ invoice ระบบจะปฏิเสธ request; หาก invoice ไม่มี client จึงใช้ `client_id` จาก request เป็น fallback. Recipient ที่ไม่เคยลงทะเบียนจะถูกเพิ่มเป็น inactive และต้องให้มนุษย์อนุมัติก่อนจ่าย.

wallet address เป็น identity แบบ unique ทั่วระบบแล้ว โดยเทียบแบบไม่สนตัวพิมพ์เล็ก/ใหญ่ และค้นหาเจ้าของ wallet ได้ผ่าน `GET /api/recipients/by-wallet/{walletAddress}`. ก่อน demo ต้องรัน migration; หาก migration พบ wallet ซ้ำ ให้แก้ข้อมูลด้วยมนุษย์ก่อน ไม่ให้ระบบเดารวม client เอง.

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
6. ทดสอบ `/demo/reset` บน PostgreSQL สำเร็จแล้ว: ลบ settlement events, webhook events และ settlements ตามลำดับโดยไม่ชน foreign key.

เงื่อนไขผ่านบางส่วน: AC4.2, true concurrency test และ demo reset ผ่านบน PostgreSQL แล้ว. Poller regression test ยืนยัน locally ว่า invoice ที่ `CONFIRMED` ไม่เพิ่ม duplicate audit event; ยังต้องยืนยันกับ SolidInvoice จริง.

## ระยะ 3: เตรียม Arc testnet

1. รัน `php artisan migrate --force` ใน container `bridge` ก่อน เพื่อใช้ unique wallet constraint. ถ้าพบ duplicate wallet migration จะหยุด ให้แก้ record เหล่านั้นก่อนโดยไม่ merge client อัตโนมัติ.
2. สร้าง Circle developer-controlled wallet บน Arc Testnet และเติม test USDC ใน wallet สำหรับจ่าย.
3. ตั้งค่าใน PowerShell session ที่ใช้เริ่ม Compose: `SETTLEMENT_PROVIDER=arc`, `CIRCLE_API_KEY`, `CIRCLE_ENTITY_SECRET`, `ARC_WALLET_ID`, `ARC_WALLET_ADDRESS`, token/USDC address และ `ARC_WEBHOOK_SECRET`; แล้ว recreate `bridge` และ `poller`. ห้าม commit secret.
4. สถานะล่าสุดของ `php artisan settlements:circle-check`: ติดต่อ Circle ได้ แต่ container ยังไม่มี API key, entity secret และ wallet ID จึงได้ `401`. หลัง recreate ให้รัน `php artisan optimize:clear` และ `php artisan settlements:circle-check` ซ้ำ; ต้อง fetch public key และพบ wallet จึงไปขั้นถัดไป.
5. การตั้งค่า Arc ถูก fail-closed แล้ว: Arc provider จะปฏิเสธ unsigned webhook แม้เป็น local environment และ `.env` ตั้ง `SETTLEMENT_ALLOW_UNSIGNED_WEBHOOKS=false`.
6. ใน SolidInvoice สร้าง invoice `pending` ที่มี client `crypto_address`, poll หนึ่งครั้ง, อนุมัติ recipient ใน dashboard, แล้ว poll ซ้ำ. ตรวจว่า payment API ของ SolidInvoice บันทึก idempotency reference ได้; ใช้ API token ที่ยัง valid.
7. ส่ง invoice หนึ่งรายการผ่าน Arc, เก็บ settlement ID, provider transaction ID และ tx hash, แล้วส่ง webhook ที่ลงลายมือชื่อถูกต้องเพื่อยืนยัน. ตรวจ event timeline ว่า `SUBMITTED` มาก่อน `CONFIRMED` และ invoice เป็น `PAID` หลัง confirmation เท่านั้น; replay webhook เดิมหนึ่งครั้งเพื่อยืนยันว่า reconciliation ไม่ซ้ำ.
8. เพิ่ม integration test ที่ใช้ Circle sandbox/webhook contract เมื่อ credentials พร้อม. เก็บ secret ใน environment/secret manager เท่านั้น.

เงื่อนไขผ่าน: มีหลักฐาน tx hash ของ Arc testnet, webhook ที่ replay แล้วไม่ทำ reconciliation ซ้ำ, และ failed/invalid evidence ยังปล่อย invoice เป็น `UNPAID`.

## ระยะ 4: เตรียมเดโมและส่งงาน

1. ทำเดโม normal flow: `UNPAID -> SUBMITTED -> CONFIRMED -> PAID`.
2. ทำเดโม duplicate pressure: ยิง request เดียวกัน 10 ครั้งและแสดงว่ามี settlement/provider transfer เดียว.
3. ทำเดโม failure/timeout: invoice ยัง `UNPAID`, settlement เป็น `SUBMISSION_UNKNOWN`, จากนั้นรัน `php artisan settlements:recover-unknown` และยืนยันว่าใช้ provider idempotency key เดิม.
4. เปิด dashboard เพื่อแสดง append-only events และหลักฐาน transaction ที่ verify แล้ว.
5. ทดสอบ fresh clone ตาม README, ตรวจว่าไม่มี credential ใน Git, ทบทวน Docker/README, บันทึกวิดีโอสำรอง, แล้ว tag `v0.1.0`.

## หลัง MVP

ทำงานส่วนขยายหลังจาก demo path เสถียรเท่านั้น: queue/scheduler monitoring, adapters สำหรับ Invoice Ninja/ERPNext/Odoo, settlement providers เพิ่มเติม และ AI recommendation ที่ต้องมีการอนุมัติของมนุษย์ก่อน execute. งานเหล่านี้ต้องเรียกผ่าน contracts เดิม และห้ามข้าม state machine หรือ audit trail.
