<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904221627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename legacy hand-written indexes to match Doctrine\'s default naming convention';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_attendee_event TO IDX_1150D56771F7E88B');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_attendee_user TO IDX_1150D567A76ED395');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_attendee_added_by TO IDX_1150D56755B127A4');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_attendee_staffing_requirement TO IDX_1150D5678D57CA40');
        $this->addSql('ALTER TABLE certification RENAME INDEX uniq_certification_name TO UNIQ_6C3C6D755E237E06');
        $this->addSql('ALTER TABLE declaration RENAME INDEX idx_declaration_cert TO IDX_7AA3DAC2CB47068A');
        $this->addSql('ALTER TABLE event RENAME INDEX idx_event_author TO IDX_3BAE0AA7F675F31B');
        $this->addSql('ALTER TABLE event_certification RENAME INDEX idx_event_cert_event TO IDX_4865DDD971F7E88B');
        $this->addSql('ALTER TABLE event_certification RENAME INDEX idx_event_cert_cert TO IDX_4865DDD9CB47068A');
        $this->addSql('ALTER TABLE event_staffing_requirement RENAME INDEX idx_staffing_requirement_event TO IDX_36CF112171F7E88B');
        $this->addSql('ALTER TABLE event_staffing_requirement RENAME INDEX idx_staffing_requirement_cert TO IDX_36CF1121CB47068A');
        $this->addSql('ALTER TABLE news_post RENAME INDEX uniq_news_post_slug TO UNIQ_8F579A06989D9B62');
        $this->addSql('ALTER TABLE news_post RENAME INDEX idx_news_post_author TO IDX_8F579A06F675F31B');
        $this->addSql('ALTER TABLE note RENAME INDEX idx_note_user TO IDX_CFBDFA14A76ED395');
        $this->addSql('ALTER TABLE note RENAME INDEX idx_note_added_by TO IDX_CFBDFA1455B127A4');
        $this->addSql('ALTER TABLE payment RENAME INDEX uniq_payment_stripe_payment_intent_id TO UNIQ_6D28840DFC72F97E');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_payment_user TO IDX_6D28840DA76ED395');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_payment_attendee TO IDX_6D28840DBCFD782A');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_payment_taken_by TO IDX_6D28840D17F014F6');
        $this->addSql('ALTER TABLE refund RENAME INDEX uniq_refund_stripe_refund_id TO UNIQ_5B2C1458A715271F');
        $this->addSql('ALTER TABLE refund RENAME INDEX idx_refund_payment TO IDX_5B2C14584C3A3BB');
        $this->addSql('ALTER TABLE refund RENAME INDEX idx_refund_created_by TO IDX_5B2C1458B03A8386');
        $this->addSql('ALTER TABLE user RENAME INDEX idx_user_parent TO IDX_8D93D649727ACA70');
        $this->addSql('ALTER TABLE user RENAME INDEX idx_user_deleted_by TO IDX_8D93D649C76F1F52');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_user TO IDX_82B2C025A76ED395');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_cert TO IDX_82B2C025CB47068A');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_started_by TO IDX_82B2C0259740C9D5');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_completed_by TO IDX_82B2C02585ECDE76');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_approved_by TO IDX_82B2C0252D234F6A');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_user_cert_cancelled_by TO IDX_82B2C025187B2D12');
        $this->addSql('ALTER TABLE user_certification_declaration RENAME INDEX idx_uc_decl_uc TO IDX_C7859B69B6524457');
        $this->addSql('ALTER TABLE user_certification_declaration RENAME INDEX idx_uc_decl_decl TO IDX_C7859B69C06258A3');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_1150d56771f7e88b TO IDX_ATTENDEE_EVENT');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_1150d5678d57ca40 TO IDX_ATTENDEE_STAFFING_REQUIREMENT');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_1150d567a76ed395 TO IDX_ATTENDEE_USER');
        $this->addSql('ALTER TABLE attendee RENAME INDEX idx_1150d56755b127a4 TO IDX_ATTENDEE_ADDED_BY');
        $this->addSql('ALTER TABLE certification RENAME INDEX uniq_6c3c6d755e237e06 TO UNIQ_CERTIFICATION_NAME');
        $this->addSql('ALTER TABLE declaration RENAME INDEX idx_7aa3dac2cb47068a TO IDX_DECLARATION_CERT');
        $this->addSql('ALTER TABLE event RENAME INDEX idx_3bae0aa7f675f31b TO IDX_EVENT_AUTHOR');
        $this->addSql('ALTER TABLE event_certification RENAME INDEX idx_4865ddd9cb47068a TO IDX_EVENT_CERT_CERT');
        $this->addSql('ALTER TABLE event_certification RENAME INDEX idx_4865ddd971f7e88b TO IDX_EVENT_CERT_EVENT');
        $this->addSql('ALTER TABLE event_staffing_requirement RENAME INDEX idx_36cf112171f7e88b TO IDX_STAFFING_REQUIREMENT_EVENT');
        $this->addSql('ALTER TABLE event_staffing_requirement RENAME INDEX idx_36cf1121cb47068a TO IDX_STAFFING_REQUIREMENT_CERT');
        $this->addSql('ALTER TABLE news_post RENAME INDEX idx_8f579a06f675f31b TO IDX_NEWS_POST_AUTHOR');
        $this->addSql('ALTER TABLE news_post RENAME INDEX uniq_8f579a06989d9b62 TO UNIQ_NEWS_POST_SLUG');
        $this->addSql('ALTER TABLE note RENAME INDEX idx_cfbdfa1455b127a4 TO IDX_NOTE_ADDED_BY');
        $this->addSql('ALTER TABLE note RENAME INDEX idx_cfbdfa14a76ed395 TO IDX_NOTE_USER');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_6d28840dbcfd782a TO IDX_PAYMENT_ATTENDEE');
        $this->addSql('ALTER TABLE payment RENAME INDEX uniq_6d28840dfc72f97e TO UNIQ_PAYMENT_STRIPE_PAYMENT_INTENT_ID');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_6d28840da76ed395 TO IDX_PAYMENT_USER');
        $this->addSql('ALTER TABLE payment RENAME INDEX idx_6d28840d17f014f6 TO IDX_PAYMENT_TAKEN_BY');
        $this->addSql('ALTER TABLE refund RENAME INDEX idx_5b2c1458b03a8386 TO IDX_REFUND_CREATED_BY');
        $this->addSql('ALTER TABLE refund RENAME INDEX idx_5b2c14584c3a3bb TO IDX_REFUND_PAYMENT');
        $this->addSql('ALTER TABLE refund RENAME INDEX uniq_5b2c1458a715271f TO UNIQ_REFUND_STRIPE_REFUND_ID');
        $this->addSql('ALTER TABLE `user` RENAME INDEX idx_8d93d649727aca70 TO IDX_USER_PARENT');
        $this->addSql('ALTER TABLE `user` RENAME INDEX idx_8d93d649c76f1f52 TO IDX_USER_DELETED_BY');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c025a76ed395 TO IDX_USER_CERT_USER');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c0259740c9d5 TO IDX_USER_CERT_STARTED_BY');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c02585ecde76 TO IDX_USER_CERT_COMPLETED_BY');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c025cb47068a TO IDX_USER_CERT_CERT');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c025187b2d12 TO IDX_USER_CERT_CANCELLED_BY');
        $this->addSql('ALTER TABLE user_certification RENAME INDEX idx_82b2c0252d234f6a TO IDX_USER_CERT_APPROVED_BY');
        $this->addSql('ALTER TABLE user_certification_declaration RENAME INDEX idx_c7859b69c06258a3 TO IDX_UC_DECL_DECL');
        $this->addSql('ALTER TABLE user_certification_declaration RENAME INDEX idx_c7859b69b6524457 TO IDX_UC_DECL_UC');
    }
}
