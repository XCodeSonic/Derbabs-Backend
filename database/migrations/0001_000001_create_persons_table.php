    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('persons', function (Blueprint $table) {
                $table->id('person_id');
                $table->string('first_name',80);
                $table->string('last_name',80);
                $table->string('middle_name',80)->nullable();
                $table->string('suffix',20)->nullable();
                $table->string('email',120)->unique();
                $table->string('phone',30)->nullable();
                $table->string('alternate_phone',30)->nullable();
                $table->text('address_line_1')->nullable();
                $table->text('address_line_2')->nullable();
                $table->string('city',80)->nullable();
                $table->string('province',80)->nullable();
                $table->string('postal_code',20)->nullable();
                $table->string('country',80)->default('Philippines');
                $table->date('birth_date')->nullable();
                $table->enum('gender',['male','female','other'])->nullable();
                $table->enum('civil_status',['single','married','divorced','widowed'])->nullable();
                $table->string('profile_photo')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['first_name','last_name']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('persons');
        }
    };
