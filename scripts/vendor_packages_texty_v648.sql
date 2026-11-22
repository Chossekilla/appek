-- v3.0.648 — texty balíčků ve vendoru (checkout je čte z vendor_packages): opravy CS + chybějící EN/ES
-- Spouští se ručně proti vendor DB (lokálně appek_vendor, ostrá přes SSH). Idempotentní.
UPDATE vendor_packages SET
  description_cs = 'Základ — objednávky, faktury, výroba, HACCP, cenovky, B2B portál',
  description_en = 'The base — orders, invoices, production, HACCP, price labels, B2B portal',
  description_es = 'La base — pedidos, facturas, producción, APPCC, etiquetas de precio, portal B2B'
WHERE `key` = 'core';
UPDATE vendor_packages SET name_en = 'Confectionery',
  description_cs = 'Speciality pro cukrárny: dorty, zákusky, konfigurátor zakázek',
  description_en = 'For confectioneries: cakes, pastries, custom order configurator',
  description_es = 'Para pastelerías: tartas, pasteles, configurador de pedidos'
WHERE `key` = 'cukrarna';
UPDATE vendor_packages SET name_es = 'Delicatessen',
  description_cs = 'Lahůdky a studená kuchyně: gramáže, krabičkování, velký catering',
  description_en = 'Deli & cold kitchen: portion weights, boxing, large catering',
  description_es = 'Delicatessen y cocina fría: gramajes, envasado, gran catering'
WHERE `key` = 'lahudky';
UPDATE vendor_packages SET
  description_cs = 'Restaurace: stoly a kasa, rezervace, kuchyňský displej, ziskovost menu',
  description_en = 'Restaurant: tables and till, reservations, kitchen display, menu profitability',
  description_es = 'Restaurante: mesas y caja, reservas, pantalla de cocina, rentabilidad del menú'
WHERE `key` = 'restaurace';
