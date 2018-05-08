<?php namespace Octobro\Xendit\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateTokenizationsTable extends Migration
{
    public function up()
    {
        Schema::create('octobro_xendit_tokenizations', function(Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->string('token');
            $table->string('masked_card_number');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('octobro_xendit_tokenizations');
    }
}
