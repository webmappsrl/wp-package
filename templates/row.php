<?php
/**
 * Riga di un risultato (source con `layout` `rows`, come i percorsi nella tavola 2 del wireframe di
 * oc:8952): casella della selezione, codice, nome, dati e stato. Stampata una volta dentro
 * `<template id="wmwp-row">`; il JS la clona e riempie gli elementi `data-wmwp`. Senza destinazione il link resta
 * senza `href`; la casella si toglie se la source non permette lo scarico, e il suo nome lo scrive il JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="wmwp-row">
	<label class="wmwp-row__select"><input type="checkbox" data-wmwp="select"></label>
	<span class="wmwp-row__code" data-wmwp="code"></span>
	<span class="wmwp-row__body">
		<a class="wmwp-row__name" data-wmwp="link"><span data-wmwp="title"></span></a>
		<span class="wmwp-row__meta" data-wmwp="meta"></span>
	</span>
	<span class="wmwp-row__badge" data-wmwp="badge"></span>
</li>
