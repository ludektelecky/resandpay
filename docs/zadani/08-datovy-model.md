# 8. Datový model

Akce, služby a lokace se vedou jako typy obsahu WordPressu (CPT) kvůli editaci a SEO. Rezervace, platby a avíza se vedou ve vlastních databázových tabulkách pluginu – kvůli výkonu, atomické kontrole kapacity a snadnému reportování.

### Rezervace (vč. platby)

| **Pole** | **Popis** |
|---|---|
| ID rezervace | Interní identifikátor |
| Akce / termín | Odkaz na akci (CPT); akce, služby a termíny nesou kód v metadatech (kap. 3.8) |
| Zákazník | Jméno, e-mail, telefon, příp. firma a IČ; odkaz na účet Ultimate Member |
| Stav | Viz tabulka 7.1 |
| Variabilní symbol | Unikátní, nikdy znovu nepoužitý – primární klíč pro párování |
| Číslo proformy | Čitelný identifikátor |
| Očekávaná částka a měna | Pro ověření shody s přijatou platbou |
| Zaplacená částka | Součet přijatých plateb (sčítají se 1. část, doplatek i částečné platby); zbývá = cena − zaplaceno |
| 1. část a její splatnost | Částka a splatnost 1. části u platby ve dvou částech (kap. 3.9); jinak 0 |
| Datum vystavení a splatnosti | Splatnost vypočtená podle pravidla akce (kap. 3.6), pro logiku 7.2b |
| Zvolený způsob platby | Převodem (QR) / hotově na místě (kap. 3.7) |
| Připomínka odeslána | Čas odeslání připomínky po splatnosti (posílá se jednou) a splatnost, ke které byla poslána připomínka před splatností |
| Token PDF | Náhodný token pro odkaz ke stažení platebního pokynu |
| Způsob úhrady a spárování | Převod – automaticky (CRON) / ruční běh / ruční přiřazení; hotově na místě nebo převod ověřený na místě (označeno ručně, např. při odbavení); čas a kdo – pro dohledatelnost |
| Příznak poslední chvíle | Rezervace vytvořená v hranici pro platbu na místě (kap. 3.6) – neexpiruje |
| Poznámka, souhlasy | Poznámka pro lektorku, GDPR, obchodní podmínky, newsletter (s časem udělení) |

### Přijatá bankovní avíza

| **Pole** | **Popis** |
|---|---|
| Message-ID / UID | Ochrana proti dvojímu zpracování |
| Datum, částka, VS, protiúčet | Údaje vyčtené z avíza |
| Stav zpracování | Spárováno / nejasné / ignorováno |
| Vazba na rezervaci | Po spárování |

Doplňkově: tabulka logu běhů kontroly plateb (kap. 6.4), seznam odeslaných e-mailů (kap. 4.5), evidence plateb u rezervací – jednotlivé platby s vazbou na avízo a záznamem o odpárování (kap. 7.3) –, slevové kupóny (kód, GUID, sleva, platnost, rozsah, limity, dávka) a historie slevy u rezervace (kap. 3.10) a číselník lokací.
