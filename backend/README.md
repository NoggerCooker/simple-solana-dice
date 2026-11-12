# PHP-Backend

Die API wird als einzige öffentliche PHP-Einstiegsdatei unter `public/index.php` betrieben. Konfiguration kommt aus `backend/.env`; der QuickNode-Endpunkt bleibt serverseitig und wird nicht an den Browser ausgeliefert.

Der aktuelle Devnet-POC verwendet PHP-Sessions für den Spielstatus. Der Spieler signiert einen nativen SOL-Transfer zur Treasury; PHP sendet ihn über den konfigurierten RPC und prüft die bestätigte System-Transfer-Instruction. Gewinne werden serverseitig über die Solana-CLI mit dem Keypair aus `TREASURY_KEYPAIR_PATH` signiert; Spieler benötigen den Treasury-Key nicht. Das Keypair liegt außerhalb des Projektordners, ist nicht über HTTP erreichbar und sollte auf Devnet mit `chmod 600` geschützt werden. Ein Echtgeld-Payout sollte durch ein geprüftes Solana-Programm kontrolliert werden.
