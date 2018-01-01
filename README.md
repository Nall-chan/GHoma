[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.00-blue.svg)]()
[![Version](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Version](https://img.shields.io/badge/Symcon%20Version-4.3%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-4-3-%28Stable%29-Changelog)

# GHoma 
Ermöglich das Auffinden, Konfigurieren und Steueren
von WLAN-Steckdosen vom Hersteller G-Homa.


## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Software-Installation](#3-software-installation) 
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Anhang](#5-anhang)  
    1. [GUID der Module](#1-guid-der-module)
    2. [Hinweise](#2-hinweise)
    3. [Changlog](#3-changlog)
6. [Lizenz](#6-lizenz)

## 1. Funktionsumfang

### [G-Homa Plug:](GHPlug/)  

 - Steuern des Schaltzustands.  
 - Empfang und Darstellung des Schaltzustands.  

### [G-Homa Konfigurator:](GHConfigurator/)  

 - Auflisten alle im Netzwerk verfügbaren Geräte.  
 - Erstellen von neuen 'G-Homa Plug'-Instanzen in IPS.  
 - Abfragen der Konfiguration der Geräte.  
 - Konfigurieren der Geräte für den Betrieb mit IPS.  
 - Konfigurieren von Parametern für Netzwerk und Zeitserver der Geräte.  

## 2. Voraussetzungen

 - IPS 4.3 oder höher  
 - G-Homa WLAN-Steckdosen  

## 3. Software-Installation

**IPS 4.3:**  
   Bei privater Nutzung: Über das 'Module-Control' in IPS folgende URL hinzufügen.  
    `git://github.com/Nall-chan/GHoma.git`  

   **Bei kommerzieller Nutzung (z.B. als Errichter oder Integrator) wenden Sie sich bitte an den Autor.**  

## 4. Einrichten der Instanzen in IP-Symcon

Details sind in der Dokumentation der jeweiligen Module beschrieben.  
Es wird dingend empfohlen die Einrichtung mit dem G-Homa Konfigurator zu starten.  

Die Geräte müssen eine spezielle Konfiguration erhalten, welche über den 'Konfigurator'  
an die Geräte übertragen werden muss.  

Damit IPS die Geräte findet, müssen diese im WLAN erreichbar sein.  

Um die Geräte mit dem eigenen WLAN zu koppeln, kann die Hersteller APP genutzt werden.  

Alternativ ist es auch über folgenden Weg auch IPS möglich:  

- IPS muss auf einem Gerät installiert sein, welches über eine WLAN-Schnittstelle verfügt.  
- Die Geräte müssen auf Werkseinstellung gesetzt sein (schnelles Blinkden der LED).  
- Das neue Gerät mit einem ca. 3 Sekunden Tastendruck in den AP-Modus versetzen.  
- Der Host von der IPS installation muss mit dem AP 'G-Homa' verbunden werden (DHCP muss aktiv sein!).  
- Anschließend ist die Instanz 'G-Homa Configurator' zu öffnen. Wird kein Gerät angezeigt, so ist der Button 'Netzwerk durchsuchen' zu betätigen.  
- Wird noch immer kein Gerät gefunden, so die übergeordnete Instanz 'Multicast-Socket' öffnen und einmal neu aktivieren.  
- Das neu gefundene Gerät ist in der Liste des 'G-Homa Configurator' auszuwählen und anschließend können die WLAN-Daten im unteren Teil der Konfiguration eingetragen und anschließen mit dem Button 'Schreibe WLAN' an das Gerät zu übertragen.  
- Das Gerät startet neu und kann ab sofort mit IPS verwendet werden.  
- Weitere Konfiguration siehe im [G-Homa Konfigurator:](GHConfigurator/)  

## 5. Anhang

###  1. GUID der Module

 
| Modul              | Typ          |Prefix  | GUID                                   |
| :----------------: | :----------: | :----: | :------------------------------------: |
| GHoma Plug         | Device       | GHOMA  | {5F0CF4B0-7395-4ABF-B10F-AA0109A0F016} |
| GHoma Configurator | Configurator | GHOMA  | {535EF8FE-EE78-4385-8B61-D118FAE5AE5A} |


### 2. Hinweise  

 Die Konfiguration der Geräte kann jederzeit über ein verbundenes WLAN/LAN oder den integrierten Access-Point  
 ausgelesen und verändert werden.  
 Der Zugriff ist nicht abgesichert und somit können auch gespeicherte WLAN-Zugangsdaten  
 ausgelesen werden.  
 Die Geräte sollten somit nicht im öffentlich zugäglichen Bereich betrieben werden,  
 da über einen langen Tastendruck der AP-Modus (unverschlüsselt!) aktiviert wird und  
 alle Daten ausgelesen werden können.  
 
 Dieses Modul trennt die Verbindung von den Geräten zur (chinesichen) Cloud.  
 IPS fungiert für die Geräte als Master und eine verlorene Verbindung zu IPS wird  
 mit einer blinkenden LED an den Geräten signalisiert.  


### 3. Changlog

Version 1.0:  
 - Erstes offizielles Release  

## 6. Lizenz

  IPS-Modul:  
  [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)  