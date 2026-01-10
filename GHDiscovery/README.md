[![SDK](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Module Version](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FNall-chan%2FGHoma%2Frefs%2Fheads%2Fmaster%2Flibrary.json&query=%24.version&label=Modul%20Version&color=blue)](https://community.symcon.de/t/modul-g-homa-wlan-steckdosen/45924)
[![Symcon Version](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FNall-chan%2FGHoma%2Frefs%2Fheads%2Fmaster%2Flibrary.json&query=%24.compatibility.version&suffix=%3E&label=Symcon%20Version&color=green)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v80-v81-q3-2025/)  
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Nall-chan/GHoma/workflows/Check%20Style/badge.svg)](https://github.com/Nall-chan/GHoma/actions) [![Run Tests](https://github.com/Nall-chan/GHoma/workflows/Run%20Tests/badge.svg)](https://github.com/Nall-chan/GHoma/actions)  
[![PayPal.Me](https://img.shields.io/badge/PayPal-Me-lightblue.svg)](#2-spenden)
[![Wunschliste](https://img.shields.io/badge/Wunschliste-Amazon-ff69fb.svg)](#2-spenden)  

# G-Homa Discovery  <!-- omit in toc -->

Vereinfacht das Anlegen von `G-Homa Steckdose`-Instanzen in Symcon.  
Und kann zum konfigurieren von den Geräten genutzt werden.  

## Inhaltsverzeichnis  <!-- omit in toc -->

- [1. Funktionsumfang](#1-funktionsumfang)
- [2. Voraussetzungen](#2-voraussetzungen)
- [3. Software-Installation](#3-software-installation)
- [4. Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
  - [Geräte im Netzwerk auffinden](#geräte-im-netzwerk-auffinden)
  - [Geräte für Symcon konfigurieren](#geräte-für-symcon-konfigurieren)
- [5. Statusvariablen](#5-statusvariablen)
- [6. Visualisierung](#6-visualisierung)
- [7. PHP-Befehlsreferenz](#7-php-befehlsreferenz)
- [8. Aktionen](#8-aktionen)
- [9. Anhang](#9-anhang)
  - [1. Changelog](#1-changelog)
  - [2. Spenden](#2-spenden)
- [10. Lizenz](#10-lizenz)

## 1. Funktionsumfang

- Auslesen und darstellen aller im Netzwerk gefundenen G-Homa WLAN-Steckdosen.  
- Einfaches Anlegen von neuen Instanzen in Symcon.  
- Auslesen und anpassen der Konfiguration in den Geräten.  

## 2. Voraussetzungen

- Symcon ab Version 8.1  
- G-Homa WLAN-Steckdosen  

## 3. Software-Installation

Dieses Modul ist Bestandteil der [GHoma-Library](../README.md#3-software-installation).  

## 4. Einrichten der Instanzen in IP-Symcon

Das Modul ist im Dialog 'Instanz hinzufügen' unter dem Hersteller 'G-Homa' zufinden.  
![Instanz hinzufügen](../imgs/add1.png)  

Alternativ ist es auch in der Liste alle Discovery-Instanzen aufgeführt.  
![Instanz hinzufügen](../imgs/add2.png)  

### Geräte im Netzwerk auffinden  

Beim öffnen des Discovery wird folgender Dialog angezeigt.  
![Discovery](imgs/conf.png)  

### Geräte für Symcon konfigurieren  

Über das selektieren eines Eintrages in der Tabelle und anschließenden betätigen des Button `Ausgewähltes Gerät konfigurieren`, können verschiedene Einstellungen im Gerät geändert werden.  
![Discovery](imgs/conf2.png)  
Bevor ein Gerät in Symcon benutzt werden kann, muss es einmalig passend umkonfiguriert werden.  
Dies wird durch Auswahl oder Eingabe der Symcon IP-Adresse unter `Symcon koppeln` und mit betätigen des Button `koppeln`, durchgeführt.  

## 5. Statusvariablen

Die Discovery Instanz erstellt kein Statusvariablen.  

## 6. Visualisierung

Die Discovery Instanz besitzt keine darstellbaren Elemente.  

## 7. PHP-Befehlsreferenz

Die Discovery Instanz besitzt keine Instanz-Funktionen.  

## 8. Aktionen

Es gibt keine speziellen Aktionen für dieses Modul.  

## 9. Anhang

### 1. Changelog

[Changelog der Library](../README.md#1-changelog)

### 2. Spenden

  Die Library ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:  

[![PayPal.Me](https://img.shields.io/badge/PayPal-Me-lightblue.svg)](https://paypal.me/Nall4chan)  

[![Wunschliste](https://img.shields.io/badge/Wunschliste-Amazon-ff69fb.svg)](https://www.amazon.de/hz/wishlist/ls/YU4AI9AQT9F?ref_=wl_share)  

## 10. Lizenz

IPS-Modul:  
[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
