-- Migration 002 — Conversione MyISAM → InnoDB per tabelle transazionali
-- MyISAM non supporta transazioni né FK; le operazioni di prestito/catalogo
-- usano BEGIN/COMMIT che non hanno effetto su tabelle MyISAM, rendendo
-- impossibile il rollback in caso di errore.
--
-- ATTENZIONE: su tabelle grandi questa operazione acquisisce un lock
-- per tutta la sua durata. Eseguire in finestra di manutenzione.

-- biblio_copy: usata nel checkout/checkin dentro transazioni
ALTER TABLE `biblio_copy`    ENGINE = InnoDB;

-- biblio_field: usata nell'inserimento MARC dentro transazioni
ALTER TABLE `biblio_field`   ENGINE = InnoDB;

-- biblio_hold: usata nella gestione prenotazioni dentro transazioni
ALTER TABLE `biblio_hold`    ENGINE = InnoDB;

-- member_account: coerenza architetturale
ALTER TABLE `member_account` ENGINE = InnoDB;
