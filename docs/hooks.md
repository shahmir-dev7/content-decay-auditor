# Hooks

## Filters

### `cdaudit_post_types`
Post types that are audited. Default `array( 'post', 'page' )`.

```php
add_filter( 'cdaudit_post_types', fn( $types ) => array_merge( $types, array( 'product' ) ) );
```

### `cdaudit_score`
Final decay score (0-100) of a post, after the built-in rules.

```php
add_filter( 'cdaudit_score', function ( $score, $post ) {
	return has_category( 'evergreen', $post ) ? 0 : $score;
}, 10, 2 );
```

| Param | Type | Description |
|---|---|---|
| `$score` | int | Score from the built-in rules. |
| `$post` | WP_Post | The post being scored. |

## Scoring rules (built in)
| Signal | Points |
|---|---|
| Not updated or reviewed for over 2 years / 1 year / 6 months | 40 / 30 / 15 |
| Published over 3 years / 2 years ago | 10 / 5 |
| Under 150 words / under 300 words | 25 / 12 |
| Each broken internal link (max 25) | 12 |