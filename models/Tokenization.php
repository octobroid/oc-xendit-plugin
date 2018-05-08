<?php namespace Octobro\Xendit\Models;

use Model;

/**
 * Tokenization Model
 */
class Tokenization extends Model
{
    /**
     * @var string The database table used by the model.
     */
    public $table = 'octobro_xendit_tokenizations';

    /**
     * @var array Guarded fields
     */
    protected $guarded = ['*'];

    /**
     * @var array Fillable fields
     */
    protected $fillable = ['token', 'masked_card_number'];

    /**
     * @var array Relations
     */
    public $hasOne = [];
    public $hasMany = [];
    public $belongsTo = [];
    public $belongsToMany = [];
    public $morphTo = [];
    public $morphOne = [];
    public $morphMany = [];
    public $attachOne = [];
    public $attachMany = [];
}
