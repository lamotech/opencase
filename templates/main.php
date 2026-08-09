<?php
/**
 * Main page template for OpenCase.
 * Vue mounts directly to #content (the NC core wrapper), exactly like
 * the built-in files app does. An extra mount div would nest NcContent
 * inside #content and cause a double margin-top: var(--header-height).
 */

\OCP\Util::addScript('opencase', 'opencase-main');
