[![SDK](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Modul%20Version-7.00-blue.svg)]()
![Version](https://img.shields.io/badge/Symcon%20Version-7.0%20%3E-green.svg)  
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Nall-chan/GHoma/workflows/Check%20Style/badge.svg)](https://github.com/Nall-chan/GHoma/actions) [![Run Tests](https://github.com/Nall-chan/GHoma/workflows/Run%20Tests/badge.svg)](https://github.com/Nall-chan/GHoma/actions)  
[![Spenden](https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_SM.gif)](../README.md/#6-spenden)  

# G-Homa Discovery  <!-- omit in toc -->
Vereinfacht das Anlegen von 'G-Home Plug'-Instanzen.  
Und kann zum konfigurieren von den Geräten genutzt werden.  

## Inhaltsverzeichnis  <!-- omit in toc -->

- [1. Funktionsumfang](#1-funktionsumfang)
- [2. Voraussetzungen](#2-voraussetzungen)
- [3. Software-Installation](#3-software-installation)
- [4. Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
- [5. Statusvariablen und Profile](#5-statusvariablen-und-profile)
- [6. WebFront](#6-webfront)
- [7. PHP-Befehlsreferenz](#7-php-befehlsreferenz)
- [8. Changelog](#8-changelog)
- [9. Lizenz](#9-lizenz)

## 1. Funktionsumfang

 - Auslesen und darstellen aller im Netzwerk gefundenen G-Home WLAN-Steckdosen.  
 - Einfaches Anlegen von neuen Instanzen in IPS.  
 - Auslesen und anpassen der Konfiguration in den Geräten.  

## 2. Voraussetzungen

 - IPS 7.0 oder höher  
 - G-Homa WLAN-Steckdosen  

## 3. Software-Installation

 Dieses Modul ist Bestandteil der [GHoma-Library](../README.md#3-software-installation).  

## 4. Einrichten der Instanzen in IP-Symcon

Das Modul ist im Dialog 'Instanz hinzufügen' unter dem Hersteller 'G-Homa' zufinden.  
![Instanz hinzufügen](../imgs/add1.png)  

Alternativ ist es auch in der Liste alle Discovery-Instanzen aufgeführt.  
![Instanz hinzufügen](../imgs/add2.png)  

Beim öffnen des Discovery wird folgender Dialog angezeigt.  
![Discovery](../imgs/conf.png)  

Über das selektieren eines Eintrages in der Tabelle und anschließenden betätigen des Button `Ausgewähltes Gerät konfigurieren`, 
können verschiedene Einstellungen im Gerät geändert werden.  

Bevor ein Gerät in IPS benutzt werden kann, muss es einmalig für Symcon umkonfiguriert werden.  
Dies wird durch Auswahl oder Eingabe der Symcon IP-Adresse unter `Symcon koppeln` mit betätigen des Button `koppeln`, durchgeführt.  

## 5. Statusvariablen und Profile

Der Discovery besitzt keine Statusvariablen und Variablenprofile.  

## 6. WebFront

Der Discovery besitzt keine im WebFront darstellbaren Elemente.  

## 7. PHP-Befehlsreferenz

Der Discovery besitzt keine dokumentierten Instanz-Funktionen.  

## 8. Changelog

[Changelog der Library](../README.md#3-changelog)  

## 9. Lizenz

  IPS-Modul:  
  [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
