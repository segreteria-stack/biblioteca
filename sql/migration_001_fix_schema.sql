-- Migration 001 — Fix schema per supporto bcrypt e integrità dati
-- Da eseguire una volta sul database di produzione.
-- Sicura da rieseguire (usa IF NOT EXISTS / IGNORE dove possibile).

-- ============================================================
-- 1. staff.pwd: char(32) → varchar(255)
--    Necessario per l'upgrade automatico MD5 → bcrypt al login.
--    char(32) tronca silenziosamente i 60 caratteri del bcrypt.
-- ============================================================
ALTER TABLE `staff`
  MODIFY `pwd` varchar(255) NOT NULL;

-- ============================================================
-- 2. member.pass_user: char(32) → varchar(255)
--    Colonna legacy; patron_area.php ora scrive in patron_auth,
--    ma allarghiamo anche questa per coerenza e sicurezza.
-- ============================================================
ALTER TABLE `member`
  MODIFY `pass_user` varchar(255) DEFAULT NULL;

-- ============================================================
-- 3. biblio_status_hist: aggiungi PRIMARY KEY auto-increment
--    Attualmente la tabella non ha PK: ammette righe duplicate
--    e l'app usa un workaround "+1 secondo" per gestirle.
-- ============================================================
ALTER TABLE `biblio_status_hist`
  ADD COLUMN `histid` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY (`histid`);

-- ============================================================
-- 4. member.barcode_nmbr: aggiungi indice UNIQUE
--    Usato per lookup ad ogni login patron e checkout prestito,
--    senza indice causa full table scan.
-- ============================================================
ALTER TABLE `member`
  ADD UNIQUE KEY `uq_member_barcode` (`barcode_nmbr`);
