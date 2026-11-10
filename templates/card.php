<?php
/**
 * Card di un risultato (tab «Tutti» e source con `layout` `cards`), stampata una volta dentro
 * `<template id="wmwp-card">`: il JS la clona e riempie gli elementi `data-wmwp`. Senza destinazione il link
 * resta senza `href`. Classe del tipo: `wmwp-card--type-<typeKey>`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="wmwp-card">
	<a class="wmwp-card__link" data-wmwp="link">
		<span class="wmwp-card__media"><img class="wmwp-card__image" data-wmwp="image" alt="" loading="lazy" decoding="async"></span>
		<span class="wmwp-card__body">
			<span class="wmwp-card__type" data-wmwp="type"></span>
			<span class="wmwp-card__title" data-wmwp="title"></span>
			<span class="wmwp-card__meta" data-wmwp="meta"></span>
		</span>
	</a>
</li>
