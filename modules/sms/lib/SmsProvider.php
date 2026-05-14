<?php
/**
 * SmsProvider Interface
 *
 * Tüm SMS sağlayıcı implementasyonları bu interface'i uygulamalıdır.
 * İleride NetGSM, İletimerkezi vb. eklenebilir.
 */
interface SmsProvider
{
    /**
     * SMS gönder.
     *
     * @param string|array $phones   Tek numara (string) veya çoklu (array)
     * @param string       $message  Mesaj metni
     * @return array ['success'=>bool, 'message'=>string, 'response'=>array, 'success_count'=>int]
     */
    public function send($phones, string $message): array;

    /**
     * Sağlayıcı kimliği — DB'de saklanır (örn. 'generic_form_post')
     */
    public function getId(): string;
}
